package id.pati.parking.data.remote

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonElement

/*
 * Wire formats of the backend API (see docs/api). Every response uses the envelope
 * { success, data, meta, error }. Amounts are integer rupiah; times are ISO-8601 with offset.
 */

@Serializable
data class Envelope(
    val success: Boolean,
    val data: JsonElement? = null,
    val meta: JsonElement? = null,
    val error: ApiErrorBody? = null,
)

@Serializable
data class ApiErrorBody(
    val code: String,
    val message: String,
    val details: JsonElement? = null,
)

@Serializable
data class TokenPair(
    @SerialName("token_type") val tokenType: String,
    @SerialName("access_token") val accessToken: String,
    @SerialName("access_token_expires_at") val accessTokenExpiresAt: String,
    @SerialName("refresh_token") val refreshToken: String,
    @SerialName("refresh_token_expires_at") val refreshTokenExpiresAt: String,
)

@Serializable
data class LoginData(
    @SerialName("token_type") val tokenType: String,
    @SerialName("access_token") val accessToken: String,
    @SerialName("access_token_expires_at") val accessTokenExpiresAt: String,
    @SerialName("refresh_token") val refreshToken: String,
    @SerialName("refresh_token_expires_at") val refreshTokenExpiresAt: String,
    val user: UserDto,
    val device: DeviceDto? = null,
) {
    fun tokens() = TokenPair(tokenType, accessToken, accessTokenExpiresAt, refreshToken, refreshTokenExpiresAt)
}

@Serializable
data class UserDto(
    val id: Long,
    val username: String,
    val name: String,
    @SerialName("account_type") val accountType: String,
    val roles: List<String> = emptyList(),
    val permissions: List<String> = emptyList(),
)

@Serializable
data class DeviceDto(
    val uuid: String,
    val status: String,
    @SerialName("status_label") val statusLabel: String? = null,
)

@Serializable
data class Bootstrap(
    @SerialName("generated_at") val generatedAt: String,
    @SerialName("valid_until") val validUntil: String,
    @SerialName("business_date") val businessDate: String,
    @SerialName("business_timezone") val businessTimezone: String,
    val attendant: AttendantDto,
    val device: DeviceDto,
    val assignment: AssignmentDto? = null,
    val location: LocationDto? = null,
    val tariffs: List<TariffDto> = emptyList(),
    val settings: Map<String, Int> = emptyMap(),
    @SerialName("open_shift") val openShift: ShiftDto? = null,
)

@Serializable
data class AttendantDto(
    @SerialName("attendant_code") val attendantCode: String,
    val name: String,
    val status: String,
)

@Serializable
data class AssignmentDto(
    val id: Long,
    @SerialName("location_id") val locationId: Long,
    @SerialName("effective_from") val effectiveFrom: String,
    @SerialName("effective_until") val effectiveUntil: String? = null,
)

@Serializable
data class LocationDto(
    val id: Long,
    @SerialName("location_code") val locationCode: String,
    val name: String,
    val address: String? = null,
    val latitude: String,
    val longitude: String,
    @SerialName("geofence_radius_m") val geofenceRadiusM: Int,
    @SerialName("location_type") val locationType: String,
    val status: String,
)

@Serializable
data class TariffDto(
    @SerialName("tariff_id") val tariffId: Long,
    @SerialName("vehicle_type") val vehicleType: String,
    val amount: Long,
    @SerialName("location_specific") val locationSpecific: Boolean,
    @SerialName("effective_from") val effectiveFrom: String,
    @SerialName("effective_until") val effectiveUntil: String? = null,
)

@Serializable
data class ShiftDto(
    @SerialName("shift_uuid") val shiftUuid: String,
    val status: String,
    @SerialName("offline_created") val offlineCreated: Boolean = false,
    @SerialName("started_at_device") val startedAtDevice: String,
    @SerialName("ended_at_device") val endedAtDevice: String? = null,
    @SerialName("review_flags") val reviewFlags: List<String> = emptyList(),
)

@Serializable
data class ShiftResult(val shift: ShiftDto, val replayed: Boolean)

@Serializable
data class TransactionDto(
    @SerialName("transaction_uuid") val transactionUuid: String,
    @SerialName("transaction_number") val transactionNumber: String,
    val status: String,
    @SerialName("charged_amount") val chargedAmount: Long,
    @SerialName("expected_amount") val expectedAmount: Long? = null,
    @SerialName("review_flags") val reviewFlags: List<String> = emptyList(),
)

@Serializable
data class TransactionResult(
    val transaction: TransactionDto,
    val replayed: Boolean,
    @SerialName("cash_balance") val cashBalance: Long,
)

@Serializable
data class SyncItemError(val code: String, val message: String, val retryable: Boolean = false)

@Serializable
data class SyncItemResult(
    val index: Int,
    @SerialName("transaction_uuid") val transactionUuid: String? = null,
    val result: String,
    val transaction: TransactionDto? = null,
    val error: SyncItemError? = null,
)

@Serializable
data class SyncResponse(
    val results: List<SyncItemResult>,
    @SerialName("cash_balance") val cashBalance: Long,
)

/** docs/api/payments.md */
@Serializable
data class PaymentDto(
    @SerialName("payment_uuid") val paymentUuid: String,
    val status: String,
    val amount: Long,
    @SerialName("qr_string") val qrString: String? = null,
    @SerialName("qr_image_url") val qrImageUrl: String? = null,
    @SerialName("expires_at") val expiresAt: String? = null,
    @SerialName("paid_at") val paidAt: String? = null,
)

@Serializable
data class QrisResult(val transaction: TransactionDto, val payment: PaymentDto, val replayed: Boolean = false)

@Serializable
data class PaymentView(val payment: PaymentDto, val transaction: TransactionDto)

/** docs/api/settlements.md */
@Serializable
data class CashTotals(val collected: Long, val deposited: Long, val outstanding: Long)

@Serializable
data class SettlementDto(
    @SerialName("settlement_uuid") val settlementUuid: String,
    @SerialName("settlement_number") val settlementNumber: String,
    val status: String,
    @SerialName("status_label") val statusLabel: String,
    val amount: Long,
    @SerialName("verified_amount") val verifiedAmount: Long? = null,
    @SerialName("submitted_at") val submittedAt: String,
    @SerialName("decision_note") val decisionNote: String? = null,
)

@Serializable
data class CashSummaryDto(
    @SerialName("cash_balance") val cashBalance: Long,
    val total: CashTotals,
    val today: CashTotals,
    @SerialName("pending_settlement") val pendingSettlement: SettlementDto? = null,
)

@Serializable
data class SettlementResult(val settlement: SettlementDto, val replayed: Boolean = false, @SerialName("cash_balance") val cashBalance: Long = 0)

@Serializable
data class SettlementView(val settlement: SettlementDto)

@Serializable
data class SettlementPayload(
    @SerialName("settlement_uuid") val settlementUuid: String,
    val amount: Long,
    @SerialName("shift_uuid") val shiftUuid: String? = null,
    val notes: String? = null,
)

@Serializable
data class CashBalance(@SerialName("cash_balance") val cashBalance: Long)

/** Payloads sent by the app. Field names follow docs/api/shifts.md and transactions.md. */
@Serializable
data class StartShiftPayload(
    @SerialName("shift_uuid") val shiftUuid: String,
    @SerialName("location_id") val locationId: Long,
    @SerialName("started_at_device") val startedAtDevice: String,
    val latitude: Double? = null,
    val longitude: Double? = null,
    @SerialName("gps_accuracy_m") val gpsAccuracyM: Double? = null,
    @SerialName("mock_location") val mockLocation: Boolean = false,
    @SerialName("offline_created") val offlineCreated: Boolean,
)

@Serializable
data class EndShiftPayload(
    @SerialName("shift_uuid") val shiftUuid: String,
    @SerialName("ended_at_device") val endedAtDevice: String,
    val latitude: Double? = null,
    val longitude: Double? = null,
    @SerialName("gps_accuracy_m") val gpsAccuracyM: Double? = null,
    @SerialName("mock_location") val mockLocation: Boolean = false,
)

@Serializable
data class CashTransactionPayload(
    @SerialName("transaction_uuid") val transactionUuid: String,
    @SerialName("shift_uuid") val shiftUuid: String,
    @SerialName("sync_sequence") val syncSequence: Long,
    @SerialName("vehicle_type") val vehicleType: String,
    @SerialName("vehicle_plate") val vehiclePlate: String? = null,
    @SerialName("payment_method") val paymentMethod: String = "CASH",
    @SerialName("charged_amount") val chargedAmount: Long,
    @SerialName("tariff_id") val tariffId: Long? = null,
    @SerialName("transaction_time_device") val transactionTimeDevice: String,
    val latitude: Double? = null,
    val longitude: Double? = null,
    @SerialName("gps_accuracy_m") val gpsAccuracyM: Double? = null,
    @SerialName("mock_location") val mockLocation: Boolean = false,
    @SerialName("offline_created") val offlineCreated: Boolean,
)
