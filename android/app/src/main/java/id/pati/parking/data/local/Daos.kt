package id.pati.parking.data.local

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query
import androidx.room.Transaction
import androidx.room.Upsert
import kotlinx.coroutines.flow.Flow

@Dao
interface ShiftDao {
    @Insert(onConflict = OnConflictStrategy.ABORT)
    suspend fun insert(shift: LocalShiftEntity)

    @Query("SELECT * FROM local_shifts WHERE status = 'OPEN' ORDER BY startedAtDevice DESC LIMIT 1")
    fun observeOpen(): Flow<LocalShiftEntity?>

    @Query("SELECT * FROM local_shifts WHERE status = 'OPEN' ORDER BY startedAtDevice DESC LIMIT 1")
    suspend fun open(): LocalShiftEntity?

    @Query("UPDATE local_shifts SET status = :status, endedAtDevice = COALESCE(:endedAt, endedAtDevice) WHERE shiftUuid = :uuid")
    suspend fun setStatus(uuid: String, status: String, endedAt: String?)
}

@Dao
interface TransactionDao {
    @Insert(onConflict = OnConflictStrategy.ABORT)
    suspend fun insert(transaction: LocalTransactionEntity)

    @Query("SELECT * FROM local_transactions WHERE shiftUuid = :shiftUuid ORDER BY syncSequence DESC")
    fun observeForShift(shiftUuid: String): Flow<List<LocalTransactionEntity>>

    @Query("UPDATE local_transactions SET syncStatus = :status, serverNumber = COALESCE(:number, serverNumber), lastError = :error WHERE transactionUuid = :uuid")
    suspend fun setSync(uuid: String, status: String, number: String?, error: String?)

    /** Cash recorded in this shift (what the attendant holds; QRIS money never passes through them). */
    @Query("SELECT COALESCE(SUM(chargedAmount), 0) FROM local_transactions WHERE shiftUuid = :shiftUuid AND paymentMethod = 'CASH'")
    fun observeShiftTotal(shiftUuid: String): Flow<Long>

    @Query("SELECT COALESCE(SUM(chargedAmount), 0) FROM local_transactions WHERE shiftUuid = :shiftUuid AND paymentMethod = 'QRIS' AND paymentStatus = 'PAID'")
    fun observeQrisTotal(shiftUuid: String): Flow<Long>

    @Query("SELECT * FROM local_transactions WHERE transactionUuid = :uuid")
    suspend fun find(uuid: String): LocalTransactionEntity?

    @Query("SELECT * FROM local_transactions WHERE shiftUuid = :shiftUuid")
    suspend fun forShift(shiftUuid: String): List<LocalTransactionEntity>

    @Query("UPDATE local_transactions SET paymentStatus = :status WHERE transactionUuid = :uuid")
    suspend fun setPaymentStatus(uuid: String, status: String)
}

@Dao
interface SyncQueueDao {
    @Insert(onConflict = OnConflictStrategy.ABORT)
    suspend fun insert(item: SyncQueueEntity): Long

    @Query("SELECT * FROM sync_queue WHERE kind = :kind AND status = 'PENDING' ORDER BY id LIMIT :limit")
    suspend fun pending(kind: String, limit: Int): List<SyncQueueEntity>

    @Query("SELECT * FROM sync_queue WHERE kind = :kind AND entityUuid = :uuid LIMIT 1")
    suspend fun find(kind: String, uuid: String): SyncQueueEntity?

    @Query("SELECT COUNT(*) FROM sync_queue WHERE kind = 'TRANSACTION' AND shiftUuid = :shiftUuid AND status IN ('PENDING', 'SYNCING')")
    suspend fun unsyncedTransactions(shiftUuid: String): Int

    @Query("UPDATE sync_queue SET status = :status, attempts = attempts + :attemptDelta, lastErrorCode = :code, lastErrorMessage = :message, updatedAtMillis = :now WHERE id = :id")
    suspend fun update(id: Long, status: String, attemptDelta: Int, code: String?, message: String?, now: Long)

    /** Items left SYNCING by a crash or kill are simply retried. */
    @Query("UPDATE sync_queue SET status = 'PENDING' WHERE status = 'SYNCING'")
    suspend fun resetInterrupted()

    @Query("SELECT COUNT(*) FROM sync_queue WHERE status IN ('PENDING', 'SYNCING')")
    fun observePendingCount(): Flow<Int>

    @Query("SELECT COUNT(*) FROM sync_queue WHERE status IN ('PENDING', 'SYNCING')")
    suspend fun pendingCount(): Int

    @Query("SELECT COUNT(*) FROM sync_queue WHERE status = 'FAILED'")
    fun observeFailedCount(): Flow<Int>
}

@Dao
interface ConfigDao {
    @Upsert
    suspend fun put(entry: LocalConfigEntity)

    @Query("SELECT * FROM local_config WHERE `key` = :key")
    suspend fun get(key: String): LocalConfigEntity?
}

@Dao
interface CounterDao {
    @Query("SELECT value FROM device_counters WHERE name = :name")
    suspend fun current(name: String): Long?

    @Upsert
    suspend fun put(counter: CounterEntity)

    /** Atomic increment; the value is never handed out twice, even after local rows are pruned. */
    @Transaction
    suspend fun next(name: String): Long {
        val next = (current(name) ?: 0L) + 1
        put(CounterEntity(name, next))
        return next
    }
}
