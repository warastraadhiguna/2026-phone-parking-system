package id.pati.parking.data.local

import androidx.room.ColumnInfo
import androidx.room.Entity
import androidx.room.Index
import androidx.room.PrimaryKey

/*
 * Local persistence (master doc §22): LocalShift, LocalTransaction, SyncQueue, LocalConfig.
 * Local rows are never deleted before the server acknowledged them (§23).
 */

@Entity(tableName = "local_shifts")
data class LocalShiftEntity(
    @PrimaryKey val shiftUuid: String,
    val locationId: Long,
    val locationCode: String,
    val locationName: String,
    val startedAtDevice: String,
    val endedAtDevice: String? = null,
    /** OPEN or CLOSED locally; FORCED_CLOSED when the server reports it. */
    val status: String,
    val offlineCreated: Boolean,
)

@Entity(
    tableName = "local_transactions",
    indices = [Index("shiftUuid"), Index(value = ["syncSequence"], unique = true)],
)
data class LocalTransactionEntity(
    @PrimaryKey val transactionUuid: String,
    val shiftUuid: String,
    val syncSequence: Long,
    val vehicleType: String,
    val vehiclePlate: String?,
    val chargedAmount: Long,
    val tariffId: Long?,
    val transactionTimeDevice: String,
    val offlineCreated: Boolean,
    /** Mirrors the queue status for display: PENDING, SYNCING, SYNCED, FAILED. */
    val syncStatus: String,
    val serverNumber: String? = null,
    val lastError: String? = null,
    /** CASH (queued, works offline) or QRIS (created online; never in the sync queue). */
    @ColumnInfo(defaultValue = "CASH") val paymentMethod: String = "CASH",
    /** QRIS only: the payment status last confirmed by the server. */
    val paymentStatus: String? = null,
    val paymentUuid: String? = null,
)

/**
 * One outgoing operation. `payloadJson` is fixed at creation and resent unchanged, so the
 * server's idempotency fingerprint matches on every retry.
 */
@Entity(
    tableName = "sync_queue",
    indices = [Index(value = ["kind", "entityUuid"], unique = true), Index("status")],
)
data class SyncQueueEntity(
    @PrimaryKey(autoGenerate = true) val id: Long = 0,
    val kind: String,
    val entityUuid: String,
    /** For TRANSACTION items: the shift they belong to (ordering). */
    val shiftUuid: String?,
    val payloadJson: String,
    val status: String,
    val attempts: Int = 0,
    val lastErrorCode: String? = null,
    val lastErrorMessage: String? = null,
    val createdAtMillis: Long,
    val updatedAtMillis: Long,
)

@Entity(tableName = "local_config")
data class LocalConfigEntity(
    @PrimaryKey val key: String,
    val value: String,
    val updatedAtMillis: Long,
)

/** Monotonic counters that must never be reused (e.g. sync_sequence, §21). */
@Entity(tableName = "device_counters")
data class CounterEntity(
    @PrimaryKey val name: String,
    val value: Long,
)

object SyncKind {
    const val SHIFT_START = "SHIFT_START"
    const val TRANSACTION = "TRANSACTION"
    const val SHIFT_END = "SHIFT_END"
}

/** Sync queue status (master doc §22). */
object SyncStatus {
    const val PENDING = "PENDING"
    const val SYNCING = "SYNCING"
    const val SYNCED = "SYNCED"
    const val FAILED = "FAILED"
}
