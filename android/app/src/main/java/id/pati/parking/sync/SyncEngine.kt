package id.pati.parking.sync

import id.pati.parking.data.local.SyncKind
import id.pati.parking.data.remote.ShiftResult
import id.pati.parking.data.remote.SyncResponse

/** Result of one API call, independent of transport. */
sealed interface ApiOutcome<out T> {
    data class Success<T>(val data: T) : ApiOutcome<T>

    /** The server answered with an error envelope. */
    data class Failure(val code: String, val message: String, val httpStatus: Int) : ApiOutcome<Nothing>

    /** No usable answer (offline, timeout, 5xx gateway, …): try again later. */
    data class NetworkError(val message: String) : ApiOutcome<Nothing>

    /** Tokens are gone or rejected: the attendant must log in again. Nothing is lost. */
    data object AuthRequired : ApiOutcome<Nothing>
}

interface SyncApi {
    suspend fun startShift(payloadJson: String): ApiOutcome<ShiftResult>
    suspend fun endShift(payloadJson: String): ApiOutcome<ShiftResult>
    suspend fun syncTransactions(payloadJsons: List<String>): ApiOutcome<SyncResponse>
}

data class QueueItem(
    val id: Long,
    val kind: String,
    val entityUuid: String,
    val shiftUuid: String?,
    val payloadJson: String,
    val attempts: Int,
)

/** Persistence the engine needs; implemented with Room (RoomSyncStore) and in tests with a fake. */
interface SyncStore {
    suspend fun resetInterrupted()
    suspend fun pending(kind: String, limit: Int): List<QueueItem>
    suspend fun isSynced(kind: String, entityUuid: String): Boolean
    suspend fun unsyncedTransactions(shiftUuid: String): Int
    suspend fun markSyncing(item: QueueItem)
    suspend fun markSynced(item: QueueItem, serverReference: String?, forcedClosed: Boolean = false)
    suspend fun markRetry(item: QueueItem, code: String, message: String)
    suspend fun markFailed(item: QueueItem, code: String, message: String)
    suspend fun saveCashBalance(balance: Long)
}

data class SyncReport(
    val synced: Int = 0,
    val failed: Int = 0,
    val deferred: Int = 0,
    /** true when something is still waiting and a later run may succeed (WorkManager retry). */
    val retryLater: Boolean = false,
    val authRequired: Boolean = false,
)

/**
 * Drains the sync queue in the order the server requires (ADR-0008):
 *   1. shift starts, 2. transactions of synced shifts (batches of 50), 3. shift ends whose
 *   transactions are all synced.
 *
 * Rules: payloads are resent unchanged (server idempotency); a network failure stops the run
 * and leaves everything PENDING; permanent rejections become FAILED for supervisor attention;
 * nothing is ever deleted here.
 */
class SyncEngine(private val api: SyncApi, private val store: SyncStore) {

    suspend fun runOnce(): SyncReport {
        store.resetInterrupted()
        var report = SyncReport()

        for (item in store.pending(SyncKind.SHIFT_START, 20)) {
            store.markSyncing(item)
            report = when (val outcome = api.startShift(item.payloadJson)) {
                is ApiOutcome.Success -> {
                    store.markSynced(item, outcome.data.shift.shiftUuid)
                    report.copy(synced = report.synced + 1)
                }
                is ApiOutcome.Failure -> handleFailure(item, outcome, report, retryable = outcome.code in SHIFT_RETRYABLE)
                is ApiOutcome.NetworkError -> return deferRest(item, outcome.message, report)
                ApiOutcome.AuthRequired -> return authRequired(item, report)
            }
        }

        while (true) {
            val batch = store.pending(SyncKind.TRANSACTION, BATCH_SIZE)
                .filter { tx -> tx.shiftUuid != null && store.isSynced(SyncKind.SHIFT_START, tx.shiftUuid) }
            if (batch.isEmpty()) break

            batch.forEach { store.markSyncing(it) }
            when (val outcome = api.syncTransactions(batch.map { it.payloadJson })) {
                is ApiOutcome.Success -> {
                    store.saveCashBalance(outcome.data.cashBalance)
                    val byIndex = outcome.data.results.associateBy { it.index }
                    var anyDeferred = false
                    batch.forEachIndexed { i, item ->
                        val r = byIndex[i]
                        report = when {
                            r == null -> {
                                anyDeferred = true
                                store.markRetry(item, "NO_RESULT", "Server tidak mengembalikan hasil untuk item ini.")
                                report.copy(deferred = report.deferred + 1, retryLater = true)
                            }
                            r.result == "CREATED" || r.result == "EXISTING" -> {
                                store.markSynced(item, r.transaction?.transactionNumber)
                                report.copy(synced = report.synced + 1)
                            }
                            r.error?.retryable == true -> {
                                anyDeferred = true
                                store.markRetry(item, r.error.code, r.error.message)
                                report.copy(deferred = report.deferred + 1, retryLater = true)
                            }
                            else -> {
                                store.markFailed(item, r.error?.code ?: "REJECTED", r.error?.message ?: "Ditolak server.")
                                report.copy(failed = report.failed + 1)
                            }
                        }
                    }
                    // Deferred items are PENDING again; stop rather than fetching them in a loop.
                    if (anyDeferred) break
                }
                is ApiOutcome.Failure -> {
                    batch.forEach { store.markRetry(it, outcome.code, outcome.message) }
                    return report.copy(deferred = report.deferred + batch.size, retryLater = true)
                }
                is ApiOutcome.NetworkError -> {
                    batch.forEach { store.markRetry(it, "NETWORK", outcome.message) }
                    return report.copy(deferred = report.deferred + batch.size, retryLater = true)
                }
                ApiOutcome.AuthRequired -> {
                    batch.forEach { store.markRetry(it, "AUTH_REQUIRED", "Login ulang diperlukan.") }
                    return report.copy(authRequired = true, retryLater = true)
                }
            }
        }

        for (item in store.pending(SyncKind.SHIFT_END, 20)) {
            val shiftUuid = item.entityUuid
            if (!store.isSynced(SyncKind.SHIFT_START, shiftUuid) || store.unsyncedTransactions(shiftUuid) > 0) {
                report = report.copy(deferred = report.deferred + 1, retryLater = true)
                continue
            }
            store.markSyncing(item)
            report = when (val outcome = api.endShift(item.payloadJson)) {
                is ApiOutcome.Success -> {
                    store.markSynced(item, shiftUuid, forcedClosed = outcome.data.shift.status == "FORCED_CLOSED")
                    report.copy(synced = report.synced + 1)
                }
                is ApiOutcome.Failure -> handleFailure(item, outcome, report, retryable = false)
                is ApiOutcome.NetworkError -> return deferRest(item, outcome.message, report)
                ApiOutcome.AuthRequired -> return authRequired(item, report)
            }
        }

        // Anything still PENDING (e.g. transactions whose shift start failed) waits for a later run.
        val leftover = store.pending(SyncKind.TRANSACTION, 1).isNotEmpty() || store.pending(SyncKind.SHIFT_START, 1).isNotEmpty()
        return if (leftover) report.copy(retryLater = true) else report
    }

    private suspend fun handleFailure(item: QueueItem, outcome: ApiOutcome.Failure, report: SyncReport, retryable: Boolean): SyncReport {
        return if (retryable || outcome.httpStatus >= 500 || outcome.httpStatus == 429) {
            store.markRetry(item, outcome.code, outcome.message)
            report.copy(deferred = report.deferred + 1, retryLater = true)
        } else {
            store.markFailed(item, outcome.code, outcome.message)
            report.copy(failed = report.failed + 1)
        }
    }

    private suspend fun deferRest(item: QueueItem, message: String, report: SyncReport): SyncReport {
        store.markRetry(item, "NETWORK", message)
        return report.copy(deferred = report.deferred + 1, retryLater = true)
    }

    private suspend fun authRequired(item: QueueItem, report: SyncReport): SyncReport {
        store.markRetry(item, "AUTH_REQUIRED", "Login ulang diperlukan.")
        return report.copy(authRequired = true, retryLater = true)
    }

    companion object {
        const val BATCH_SIZE = 50

        /** A shift start can succeed later once the device is approved or the attendant re-activated. */
        private val SHIFT_RETRYABLE = setOf("DEVICE_NOT_ALLOWED", "ACCOUNT_DISABLED", "RATE_LIMITED", "SERVICE_UNAVAILABLE", "INTERNAL_ERROR")
    }
}
