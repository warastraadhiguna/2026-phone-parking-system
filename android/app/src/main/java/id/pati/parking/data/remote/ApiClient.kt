package id.pati.parking.data.remote

import id.pati.parking.data.prefs.TokenStore
import id.pati.parking.sync.ApiOutcome
import id.pati.parking.sync.SyncApi
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import kotlinx.serialization.KSerializer
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.put
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.IOException
import java.util.UUID
import java.util.concurrent.TimeUnit

/**
 * Thin HTTP client for the backend (docs/api). Parses the standard envelope, attaches the access
 * token, and on `401 UNAUTHENTICATED` refreshes once (rotating tokens, ADR-0005) before retrying.
 * Every request carries an X-Request-Id so field problems can be traced end to end.
 */
class ApiClient(
    private val baseUrl: String,
    private val tokens: TokenStore,
    private val deviceUuid: () -> String,
) : SyncApi {
    val json = Json { ignoreUnknownKeys = true; explicitNulls = false; encodeDefaults = true }

    private val http = OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(30, TimeUnit.SECONDS)
        .build()

    private val refreshLock = Mutex()
    private val jsonMedia = "application/json".toMediaType()

    suspend fun login(username: String, password: String, deviceModel: String, androidVersion: String, appVersion: String): ApiOutcome<LoginData> {
        val body = buildJsonObject {
            put("username", username)
            put("password", password)
            put("device_uuid", deviceUuid())
            put("device_model", deviceModel)
            put("android_version", androidVersion)
            put("app_version", appVersion)
        }
        val outcome = call("POST", "api/v1/auth/login", body.toString(), LoginData.serializer(), authenticated = false)
        if (outcome is ApiOutcome.Success) tokens.save(outcome.data.tokens())

        return outcome
    }

    suspend fun logout(): ApiOutcome<JsonObject> {
        val outcome = call("POST", "api/v1/auth/logout", "{}", JsonObject.serializer())
        tokens.clear()
        return outcome
    }

    suspend fun bootstrap(): ApiOutcome<Bootstrap> = call("GET", "api/v1/bootstrap", null, Bootstrap.serializer())

    suspend fun cashBalance(): ApiOutcome<CashBalance> = call("GET", "api/v1/cash/balance", null, CashBalance.serializer())

    suspend fun cashSummary(): ApiOutcome<CashSummaryDto> = call("GET", "api/v1/cash/summary", null, CashSummaryDto.serializer())

    suspend fun submitSettlement(payloadJson: String): ApiOutcome<SettlementResult> =
        call("POST", "api/v1/settlements", payloadJson, SettlementResult.serializer())

    suspend fun cancelSettlement(settlementUuid: String): ApiOutcome<SettlementView> =
        call("POST", "api/v1/settlements/$settlementUuid/cancel", "{}", SettlementView.serializer())

    suspend fun createTransaction(payloadJson: String): ApiOutcome<TransactionResult> =
        call("POST", "api/v1/parking-transactions", payloadJson, TransactionResult.serializer())

    /** QRIS is online only; resend the same payload after a network error (idempotent). */
    suspend fun createQris(payloadJson: String): ApiOutcome<QrisResult> =
        call("POST", "api/v1/parking-transactions/qris", payloadJson, QrisResult.serializer())

    suspend fun payment(paymentUuid: String): ApiOutcome<PaymentView> =
        call("GET", "api/v1/payments/$paymentUuid", null, PaymentView.serializer())

    suspend fun cancelPayment(paymentUuid: String): ApiOutcome<PaymentView> =
        call("POST", "api/v1/payments/$paymentUuid/cancel", "{}", PaymentView.serializer())

    override suspend fun startShift(payloadJson: String): ApiOutcome<ShiftResult> =
        call("POST", "api/v1/shifts/start", payloadJson, ShiftResult.serializer())

    override suspend fun endShift(payloadJson: String): ApiOutcome<ShiftResult> =
        call("POST", "api/v1/shifts/end", payloadJson, ShiftResult.serializer())

    override suspend fun syncTransactions(payloadJsons: List<String>): ApiOutcome<SyncResponse> {
        val items = JsonArray(payloadJsons.map { json.parseToJsonElement(it).jsonObject })
        val body = JsonObject(mapOf("transactions" to items)).toString()

        return call("POST", "api/v1/sync/transactions", body, SyncResponse.serializer())
    }

    private suspend fun <T> call(method: String, path: String, body: String?, serializer: KSerializer<T>, authenticated: Boolean = true): ApiOutcome<T> {
        val first = send(method, path, body, serializer, authenticated)
        if (!authenticated || first !is ApiOutcome.Failure || first.code != "UNAUTHENTICATED") return first

        return if (refreshTokens()) send(method, path, body, serializer, authenticated) else ApiOutcome.AuthRequired
    }

    private suspend fun refreshTokens(): Boolean = refreshLock.withLock {
        val current = tokens.load() ?: return false
        val body = buildJsonObject {
            put("refresh_token", current.refreshToken)
            put("device_uuid", deviceUuid())
        }.toString()

        when (val outcome = send("POST", "api/v1/auth/refresh", body, TokenPair.serializer(), authenticated = false)) {
            is ApiOutcome.Success -> {
                tokens.save(outcome.data)
                true
            }
            // Network trouble during refresh: keep the tokens; the same refresh token may be
            // resent within the server's grace window (docs/api/auth.md).
            is ApiOutcome.NetworkError -> false
            else -> {
                tokens.clear()
                false
            }
        }
    }

    private suspend fun <T> send(method: String, path: String, body: String?, serializer: KSerializer<T>, authenticated: Boolean): ApiOutcome<T> =
        withContext(Dispatchers.IO) {
            val builder = Request.Builder()
                .url(baseUrl.trimEnd('/') + "/" + path)
                .header("Accept", "application/json")
                .header("X-Request-Id", "app-" + UUID.randomUUID().toString())
                .method(method, body?.toRequestBody(jsonMedia))
            if (authenticated) {
                val access = tokens.load()?.accessToken ?: return@withContext ApiOutcome.AuthRequired
                builder.header("Authorization", "Bearer $access")
            }

            try {
                http.newCall(builder.build()).execute().use { response ->
                    val text = response.body.string()
                    val envelope = try {
                        json.decodeFromString(Envelope.serializer(), text)
                    } catch (e: Exception) {
                        return@use ApiOutcome.NetworkError("Respons server tidak dapat dibaca (HTTP ${response.code}).")
                    }

                    if (envelope.success && envelope.data != null) {
                        ApiOutcome.Success(json.decodeFromJsonElement(serializer, envelope.data))
                    } else {
                        val error = envelope.error
                        ApiOutcome.Failure(error?.code ?: "HTTP_${response.code}", error?.message ?: "Permintaan gagal.", response.code)
                    }
                }
            } catch (e: IOException) {
                ApiOutcome.NetworkError(e.message ?: "Tidak ada koneksi.")
            }
        }
}
