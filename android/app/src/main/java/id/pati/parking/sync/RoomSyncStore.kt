package id.pati.parking.sync

import androidx.room.withTransaction
import id.pati.parking.data.local.AppDatabase
import id.pati.parking.data.local.SyncKind
import id.pati.parking.data.local.SyncQueueEntity
import id.pati.parking.data.local.SyncStatus
import id.pati.parking.data.prefs.AppPrefs

/** Room-backed SyncStore. Queue status and the visible local rows are updated together. */
class RoomSyncStore(
    private val db: AppDatabase,
    private val prefs: AppPrefs,
    private val now: () -> Long = System::currentTimeMillis,
) : SyncStore {

    override suspend fun resetInterrupted() = db.syncQueue().resetInterrupted()

    override suspend fun pending(kind: String, limit: Int): List<QueueItem> =
        db.syncQueue().pending(kind, limit).map { it.toItem() }

    override suspend fun isSynced(kind: String, entityUuid: String): Boolean =
        db.syncQueue().find(kind, entityUuid)?.status == SyncStatus.SYNCED

    override suspend fun unsyncedTransactions(shiftUuid: String): Int = db.syncQueue().unsyncedTransactions(shiftUuid)

    override suspend fun markSyncing(item: QueueItem) = update(item, SyncStatus.SYNCING, 0, null, null)

    override suspend fun markSynced(item: QueueItem, serverReference: String?, forcedClosed: Boolean) = db.withTransaction {
        update(item, SyncStatus.SYNCED, 1, null, null)
        when (item.kind) {
            SyncKind.TRANSACTION -> db.transactions().setSync(item.entityUuid, SyncStatus.SYNCED, serverReference, null)
            SyncKind.SHIFT_END -> if (forcedClosed) db.shifts().setStatus(item.entityUuid, "FORCED_CLOSED", null)
        }
    }

    override suspend fun markRetry(item: QueueItem, code: String, message: String) = db.withTransaction {
        update(item, SyncStatus.PENDING, 1, code, message)
        if (item.kind == SyncKind.TRANSACTION) db.transactions().setSync(item.entityUuid, SyncStatus.PENDING, null, message)
    }

    override suspend fun markFailed(item: QueueItem, code: String, message: String) = db.withTransaction {
        update(item, SyncStatus.FAILED, 1, code, message)
        if (item.kind == SyncKind.TRANSACTION) db.transactions().setSync(item.entityUuid, SyncStatus.FAILED, null, "$code: $message")
    }

    override suspend fun saveCashBalance(balance: Long) {
        prefs.lastCashBalance = balance
    }

    private suspend fun update(item: QueueItem, status: String, attemptDelta: Int, code: String?, message: String?) =
        db.syncQueue().update(item.id, status, attemptDelta, code, message, now())

    private fun SyncQueueEntity.toItem() = QueueItem(id, kind, entityUuid, shiftUuid, payloadJson, attempts)
}
