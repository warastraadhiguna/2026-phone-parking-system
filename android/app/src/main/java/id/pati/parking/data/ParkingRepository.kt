package id.pati.parking.data

import android.content.Context
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.os.Build
import androidx.room.withTransaction
import id.pati.parking.BuildConfig
import id.pati.parking.data.local.AppDatabase
import id.pati.parking.data.local.LocalConfigEntity
import id.pati.parking.data.local.LocalShiftEntity
import id.pati.parking.data.local.LocalTransactionEntity
import id.pati.parking.data.local.SyncKind
import id.pati.parking.data.local.SyncQueueEntity
import id.pati.parking.data.local.SyncStatus
import id.pati.parking.data.prefs.AppPrefs
import id.pati.parking.data.prefs.SecureTokenStore
import id.pati.parking.data.remote.ApiClient
import id.pati.parking.data.remote.Bootstrap
import id.pati.parking.data.remote.CashSummaryDto
import id.pati.parking.data.remote.CashTransactionPayload
import id.pati.parking.data.remote.SettlementPayload
import id.pati.parking.data.remote.EndShiftPayload
import id.pati.parking.data.remote.PaymentDto
import id.pati.parking.data.remote.TransactionDto
import id.pati.parking.data.remote.StartShiftPayload
import id.pati.parking.domain.OfflineShiftRules
import id.pati.parking.domain.QrisPolicy
import id.pati.parking.domain.Price
import id.pati.parking.domain.TariffCalculator
import id.pati.parking.domain.VehicleType
import id.pati.parking.location.LocationReader
import id.pati.parking.sync.ApiOutcome
import id.pati.parking.sync.SyncWorker
import kotlinx.coroutines.flow.Flow
import java.time.OffsetDateTime
import java.time.format.DateTimeFormatter
import java.time.temporal.ChronoUnit
import java.util.UUID

/** Outcome of a user action, with an Indonesian message for the attendant. */
sealed interface ActionResult {
    data class Ok(val message: String) : ActionResult
    data class Error(val message: String) : ActionResult
}

/** A QR shown to the customer. */
data class QrisSession(
    val transactionUuid: String,
    val transactionNumber: String,
    val paymentUuid: String,
    val amount: Long,
    val status: String,
    val qrString: String?,
    val qrImageUrl: String?,
    val expiresAt: OffsetDateTime?,
)

sealed interface QrisStart {
    data class Shown(val session: QrisSession) : QrisStart
    data class Failed(val message: String) : QrisStart
}

/**
 * Offline-first operations (master doc §3.5, §20–§23; ADR-0008). Every operation is written to
 * Room together with its sync-queue item in one local transaction, then the sync worker is
 * triggered. Online and offline follow the same path, so nothing is lost when connectivity drops.
 */
class ParkingRepository(
    private val context: Context,
    private val db: AppDatabase,
    private val api: ApiClient,
    private val tokens: SecureTokenStore,
    private val prefs: AppPrefs,
    private val location: LocationReader,
) {
    private val json = api.json

    fun isLoggedIn(): Boolean = tokens.load() != null

    suspend fun login(username: String, password: String): ActionResult =
        when (val outcome = api.login(username.trim(), password, "${Build.MANUFACTURER} ${Build.MODEL}", Build.VERSION.RELEASE, BuildConfig.VERSION_NAME)) {
            is ApiOutcome.Success -> {
                prefs.username = outcome.data.user.username
                prefs.hasLoggedInBefore = true
                refreshBootstrap()
                SyncWorker.schedule(context)
                val device = outcome.data.device
                ActionResult.Ok(if (device?.status == "ACTIVE") "Login berhasil." else "Login berhasil. Perangkat menunggu persetujuan admin.")
            }
            is ApiOutcome.Failure -> ActionResult.Error(outcome.message)
            is ApiOutcome.NetworkError -> ActionResult.Error("Tidak dapat terhubung ke server. Login pertama harus online.")
            ApiOutcome.AuthRequired -> ActionResult.Error("Login gagal.")
        }

    suspend fun logout() {
        api.logout()
    }

    /** Downloads the offline cache (docs/api/shifts.md). Silent when offline. */
    /** Why the last bootstrap download failed, in words for the attendant (null = it worked). */
    @Volatile var bootstrapProblem: String? = null
        private set

    suspend fun refreshBootstrap(): Bootstrap? {
        val outcome = api.bootstrap()
        bootstrapProblem = when (outcome) {
            is ApiOutcome.Success -> null
            is ApiOutcome.Failure -> when (outcome.code) {
                "DEVICE_NOT_ALLOWED" -> "HP ini belum disetujui admin. Minta admin menyetujuinya di Control Center (menu Perangkat), lalu tekan Muat ulang."
                "ACCOUNT_DISABLED" -> "Akun juru parkir tidak aktif. Hubungi admin."
                else -> outcome.message
            }
            is ApiOutcome.NetworkError -> NETWORK_PROBLEM
            ApiOutcome.AuthRequired -> "Sesi berakhir. Silakan keluar lalu login ulang."
        }
        if (outcome is ApiOutcome.Success) {
            db.config().put(LocalConfigEntity(KEY_BOOTSTRAP, json.encodeToString(Bootstrap.serializer(), outcome.data), System.currentTimeMillis()))
            adoptServerShift(outcome.data)
            return outcome.data
        }
        return null
    }

    suspend fun cachedBootstrap(): Bootstrap? =
        db.config().get(KEY_BOOTSTRAP)?.let { json.decodeFromString(Bootstrap.serializer(), it.value) }

    fun observeOpenShift(): Flow<LocalShiftEntity?> = db.shifts().observeOpen()
    fun observeTransactions(shiftUuid: String): Flow<List<LocalTransactionEntity>> = db.transactions().observeForShift(shiftUuid)
    fun observeShiftTotal(shiftUuid: String): Flow<Long> = db.transactions().observeShiftTotal(shiftUuid)
    fun observeQrisTotal(shiftUuid: String): Flow<Long> = db.transactions().observeQrisTotal(shiftUuid)
    fun observePendingSync(): Flow<Int> = db.syncQueue().observePendingCount()
    fun observeFailedSync(): Flow<Int> = db.syncQueue().observeFailedCount()
    fun lastCashBalance(): Long = prefs.lastCashBalance

    suspend fun priceFor(vehicle: VehicleType): Price? =
        cachedBootstrap()?.let { TariffCalculator.priceFor(vehicle, now(), it.tariffs) }

    suspend fun startShift(): ActionResult {
        if (db.shifts().open() != null) return ActionResult.Error("Masih ada shift yang berjalan.")

        // Always ask the server first; "offline" means the server could not be reached, not what the
        // phone reports about its network (some phones never mark a working network as validated).
        val fresh = refreshBootstrap()
        val online = fresh != null
        if (fresh == null && bootstrapProblem != NETWORK_PROBLEM) return ActionResult.Error(bootstrapProblem ?: "Server menolak permintaan.")
        val bootstrap = fresh ?: cachedBootstrap()
            ?: return ActionResult.Error("Belum ada data konfigurasi dan server tidak dapat dihubungi. Periksa koneksi internet.")

        if (online) {
            if (bootstrap.device.status != "ACTIVE") return ActionResult.Error("Perangkat belum disetujui admin.")
            if (bootstrap.assignment == null || bootstrap.location == null) return ActionResult.Error("Tidak ada penugasan untuk hari ini.")
        } else {
            OfflineShiftRules.violation(bootstrap, prefs.hasLoggedInBefore, now())?.let { return ActionResult.Error(it) }
        }

        val loc = bootstrap.location!!
        val gps = location.current()
        val startedAt = now()
        val payload = StartShiftPayload(
            shiftUuid = UUID.randomUUID().toString(),
            locationId = loc.id,
            startedAtDevice = format(startedAt),
            latitude = gps?.latitude, longitude = gps?.longitude, gpsAccuracyM = gps?.accuracyM, mockLocation = gps?.mock ?: false,
            offlineCreated = !online,
        )

        db.withTransaction {
            db.shifts().insert(LocalShiftEntity(payload.shiftUuid, loc.id, loc.locationCode, loc.name, payload.startedAtDevice, null, "OPEN", !online))
            enqueue(SyncKind.SHIFT_START, payload.shiftUuid, null, json.encodeToString(StartShiftPayload.serializer(), payload))
        }
        SyncWorker.schedule(context)

        return ActionResult.Ok(if (online) "Shift dimulai di ${loc.locationCode}." else "Shift dimulai offline. Akan disinkronkan saat ada koneksi.")
    }

    suspend fun recordCash(vehicle: VehicleType, plate: String?): ActionResult {
        val shift = db.shifts().open() ?: return ActionResult.Error("Mulai shift terlebih dahulu.")
        val at = now()
        val price = cachedBootstrap()?.let { TariffCalculator.priceFor(vehicle, at, it.tariffs) }
            ?: return ActionResult.Error("Tarif ${vehicle.label} tidak tersedia. Jangan menarik biaya; hubungi admin.")
        val online = isOnline()
        val gps = location.current(timeoutMs = 4_000)

        val payload = db.withTransaction {
            val payload = CashTransactionPayload(
                transactionUuid = UUID.randomUUID().toString(),
                shiftUuid = shift.shiftUuid,
                syncSequence = db.counters().next(COUNTER_SYNC_SEQUENCE),
                vehicleType = vehicle.name,
                vehiclePlate = plate?.takeIf { it.isNotBlank() },
                chargedAmount = price.amount,
                tariffId = price.tariffId,
                transactionTimeDevice = format(at),
                latitude = gps?.latitude, longitude = gps?.longitude, gpsAccuracyM = gps?.accuracyM, mockLocation = gps?.mock ?: false,
                offlineCreated = !online,
            )
            db.transactions().insert(
                LocalTransactionEntity(
                    payload.transactionUuid, shift.shiftUuid, payload.syncSequence, vehicle.name, payload.vehiclePlate,
                    payload.chargedAmount, payload.tariffId, payload.transactionTimeDevice, !online, SyncStatus.PENDING,
                ),
            )
            enqueue(SyncKind.TRANSACTION, payload.transactionUuid, shift.shiftUuid, json.encodeToString(CashTransactionPayload.serializer(), payload))
            payload
        }
        SyncWorker.schedule(context)

        return ActionResult.Ok("Tunai Rp${"%,d".format(payload.chargedAmount).replace(',', '.')} tercatat (${vehicle.label}).")
    }

    /**
     * QRIS (online only, docs/api/payments.md). A request whose answer was lost is kept and resent
     * unchanged on the next attempt for the same vehicle, so the server never creates it twice.
     */
    suspend fun startQris(vehicle: VehicleType, plate: String?): QrisStart {
        val shift = db.shifts().open() ?: return QrisStart.Failed("Mulai shift terlebih dahulu.")
        if (!isOnline()) return QrisStart.Failed("QRIS memerlukan koneksi internet. Gunakan tunai.")
        if (db.syncQueue().find(SyncKind.SHIFT_START, shift.shiftUuid)?.status != SyncStatus.SYNCED) {
            SyncWorker.schedule(context)
            return QrisStart.Failed("Shift belum tersinkron ke server. Tunggu sebentar lalu coba lagi.")
        }

        val payload = pendingQris?.takeIf { it.vehicleType == vehicle.name && it.shiftUuid == shift.shiftUuid } ?: run {
            val at = now()
            val price = cachedBootstrap()?.let { TariffCalculator.priceFor(vehicle, at, it.tariffs) }
                ?: return QrisStart.Failed("Tarif ${vehicle.label} tidak tersedia. Jangan menarik biaya; hubungi admin.")
            val gps = location.current(timeoutMs = 4_000)
            CashTransactionPayload(
                transactionUuid = UUID.randomUUID().toString(),
                shiftUuid = shift.shiftUuid,
                syncSequence = db.counters().next(COUNTER_SYNC_SEQUENCE),
                vehicleType = vehicle.name,
                vehiclePlate = plate?.takeIf { it.isNotBlank() },
                paymentMethod = "QRIS",
                chargedAmount = price.amount,
                tariffId = price.tariffId,
                transactionTimeDevice = format(at),
                latitude = gps?.latitude, longitude = gps?.longitude, gpsAccuracyM = gps?.accuracyM, mockLocation = gps?.mock ?: false,
                offlineCreated = false,
            )
        }
        pendingQris = payload

        return when (val outcome = api.createQris(json.encodeToString(CashTransactionPayload.serializer(), payload))) {
            is ApiOutcome.Success -> {
                pendingQris = null
                val session = outcome.data.let { session(it.transaction, it.payment) }
                if (db.transactions().find(payload.transactionUuid) == null) {
                    db.transactions().insert(
                        LocalTransactionEntity(
                            payload.transactionUuid, shift.shiftUuid, payload.syncSequence, vehicle.name, payload.vehiclePlate,
                            session.amount, payload.tariffId, payload.transactionTimeDevice, false, SyncStatus.SYNCED,
                            serverNumber = session.transactionNumber, paymentMethod = "QRIS", paymentStatus = session.status, paymentUuid = session.paymentUuid,
                        ),
                    )
                }
                if (QrisPolicy.isFinal(session.status)) QrisStart.Failed(QrisPolicy.message(session.status)) else QrisStart.Shown(session)
            }
            is ApiOutcome.Failure -> when (outcome.code) {
                "SERVICE_UNAVAILABLE" -> QrisStart.Failed("Penyedia pembayaran tidak dapat dihubungi. Tekan QRIS lagi untuk mencoba ulang, atau gunakan tunai.")
                "TARIFF_CHANGED" -> {
                    pendingQris = null
                    refreshBootstrap()
                    QrisStart.Failed("Tarif sudah berubah dan data telah dimuat ulang. Periksa tarif lalu coba lagi.")
                }
                else -> {
                    pendingQris = null
                    QrisStart.Failed(outcome.message)
                }
            }
            is ApiOutcome.NetworkError -> QrisStart.Failed("Koneksi terputus. Tekan QRIS lagi untuk mencoba ulang (transaksi yang sama).")
            ApiOutcome.AuthRequired -> QrisStart.Failed("Sesi berakhir. Silakan login ulang.")
        }
    }

    /** The server's current view of a payment (it asks the provider when needed). Null when unreachable. */
    suspend fun pollPayment(paymentUuid: String): PaymentDto? =
        (api.payment(paymentUuid) as? ApiOutcome.Success)?.data?.let { storeStatus(it.transaction, it.payment) }

    suspend fun cancelQris(paymentUuid: String): ActionResult = when (val outcome = api.cancelPayment(paymentUuid)) {
        is ApiOutcome.Success -> {
            val payment = storeStatus(outcome.data.transaction, outcome.data.payment)
            ActionResult.Ok(QrisPolicy.message(payment.status))
        }
        is ApiOutcome.Failure -> ActionResult.Error(outcome.message)
        is ApiOutcome.NetworkError -> ActionResult.Error("Tidak ada koneksi. QR belum dibatalkan.")
        ApiOutcome.AuthRequired -> ActionResult.Error("Sesi berakhir. Silakan login ulang.")
    }

    /** After a restart: ask about QRIS payments still waiting, so the list shows the truth. */
    suspend fun refreshOpenQris(shiftUuid: String) {
        db.transactions().forShift(shiftUuid)
            .filter { it.paymentMethod == "QRIS" && it.paymentUuid != null && !QrisPolicy.isFinal(it.paymentStatus ?: "PENDING") }
            .forEach { pollPayment(it.paymentUuid!!) }
    }

    private suspend fun storeStatus(transaction: TransactionDto, payment: PaymentDto): PaymentDto {
        db.transactions().setPaymentStatus(transaction.transactionUuid, payment.status)
        return payment
    }

    private fun session(transaction: TransactionDto, payment: PaymentDto) = QrisSession(
        transactionUuid = transaction.transactionUuid,
        transactionNumber = transaction.transactionNumber,
        paymentUuid = payment.paymentUuid,
        amount = payment.amount,
        status = payment.status,
        qrString = payment.qrString,
        qrImageUrl = payment.qrImageUrl,
        expiresAt = payment.expiresAt?.let { runCatching { OffsetDateTime.parse(it) }.getOrNull() },
    )

    @Volatile private var pendingQris: CashTransactionPayload? = null

    /** Expected / deposited / outstanding from the server ledger (docs/api/settlements.md). */
    suspend fun cashSummary(): CashSummaryDto? =
        (api.cashSummary() as? ApiOutcome.Success)?.data?.also { prefs.lastCashBalance = it.cashBalance }

    /**
     * Declares a cash deposit. Online only, and only when every transaction is on the server, so
     * that the server's expected cash includes offline work. A lost answer is retried unchanged.
     */
    suspend fun submitSettlement(amount: Long, notes: String?): ActionResult {
        if (!isOnline()) return ActionResult.Error("Setoran memerlukan koneksi internet.")
        if (db.syncQueue().pendingCount() > 0) {
            SyncWorker.schedule(context)
            return ActionResult.Error("Masih ada data yang belum tersinkron. Tunggu sinkron selesai, lalu ajukan setoran.")
        }
        if (amount <= 0) return ActionResult.Error("Jumlah setoran harus lebih dari 0.")

        val payload = pendingSettlement?.takeIf { it.amount == amount } ?: SettlementPayload(
            settlementUuid = UUID.randomUUID().toString(),
            amount = amount,
            shiftUuid = db.shifts().open()?.shiftUuid,
            notes = notes?.takeIf { it.isNotBlank() },
        )
        pendingSettlement = payload

        return when (val outcome = api.submitSettlement(json.encodeToString(SettlementPayload.serializer(), payload))) {
            is ApiOutcome.Success -> {
                pendingSettlement = null
                ActionResult.Ok("Setoran ${outcome.data.settlement.settlementNumber} diajukan. Serahkan uang ke petugas keuangan untuk diverifikasi.")
            }
            is ApiOutcome.Failure -> {
                pendingSettlement = null
                ActionResult.Error(outcome.message)
            }
            is ApiOutcome.NetworkError -> ActionResult.Error("Koneksi terputus. Tekan ajukan lagi untuk mencoba ulang (setoran yang sama).")
            ApiOutcome.AuthRequired -> ActionResult.Error("Sesi berakhir. Silakan login ulang.")
        }
    }

    suspend fun cancelSettlement(settlementUuid: String): ActionResult = when (val outcome = api.cancelSettlement(settlementUuid)) {
        is ApiOutcome.Success -> ActionResult.Ok("Pengajuan setoran dibatalkan.")
        is ApiOutcome.Failure -> ActionResult.Error(outcome.message)
        is ApiOutcome.NetworkError -> ActionResult.Error("Tidak ada koneksi.")
        ApiOutcome.AuthRequired -> ActionResult.Error("Sesi berakhir. Silakan login ulang.")
    }

    @Volatile private var pendingSettlement: SettlementPayload? = null

    suspend fun endShift(): ActionResult {
        val shift = db.shifts().open() ?: return ActionResult.Error("Tidak ada shift yang berjalan.")
        val gps = location.current()
        val payload = EndShiftPayload(
            shiftUuid = shift.shiftUuid,
            endedAtDevice = format(now()),
            latitude = gps?.latitude, longitude = gps?.longitude, gpsAccuracyM = gps?.accuracyM, mockLocation = gps?.mock ?: false,
        )

        db.withTransaction {
            db.shifts().setStatus(shift.shiftUuid, "CLOSED", payload.endedAtDevice)
            enqueue(SyncKind.SHIFT_END, shift.shiftUuid, shift.shiftUuid, json.encodeToString(EndShiftPayload.serializer(), payload))
        }
        SyncWorker.schedule(context)

        return ActionResult.Ok("Shift ditutup. Data akan dikirim setelah semua transaksi tersinkron.")
    }

    suspend fun refreshCashBalance() {
        (api.cashBalance() as? ApiOutcome.Success)?.let { prefs.lastCashBalance = it.data.cashBalance }
    }

    /** After a reinstall the server may still have an open shift: mirror it so work can continue. */
    private suspend fun adoptServerShift(bootstrap: Bootstrap) {
        val server = bootstrap.openShift ?: return
        val loc = bootstrap.location ?: return
        if (db.shifts().open() != null || db.syncQueue().find(SyncKind.SHIFT_START, server.shiftUuid) != null) return

        db.withTransaction {
            db.shifts().insert(LocalShiftEntity(server.shiftUuid, loc.id, loc.locationCode, loc.name, server.startedAtDevice, null, "OPEN", server.offlineCreated))
            enqueue(SyncKind.SHIFT_START, server.shiftUuid, null, "{}", SyncStatus.SYNCED)
        }
    }

    private suspend fun enqueue(kind: String, uuid: String, shiftUuid: String?, payloadJson: String, status: String = SyncStatus.PENDING) {
        val t = System.currentTimeMillis()
        db.syncQueue().insert(SyncQueueEntity(kind = kind, entityUuid = uuid, shiftUuid = shiftUuid, payloadJson = payloadJson, status = status, createdAtMillis = t, updatedAtMillis = t))
    }

    private fun isOnline(): Boolean {
        val cm = context.getSystemService(ConnectivityManager::class.java) ?: return false
        val caps = cm.getNetworkCapabilities(cm.activeNetwork) ?: return false

        return caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET) && caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED)
    }

    private fun now(): OffsetDateTime = OffsetDateTime.now().truncatedTo(ChronoUnit.SECONDS)

    private fun format(t: OffsetDateTime): String = t.format(DateTimeFormatter.ISO_OFFSET_DATE_TIME)

    private companion object {
        const val KEY_BOOTSTRAP = "bootstrap"
        const val NETWORK_PROBLEM = "Tidak dapat terhubung ke server. Periksa koneksi internet."
        const val COUNTER_SYNC_SEQUENCE = "sync_sequence"
    }
}
