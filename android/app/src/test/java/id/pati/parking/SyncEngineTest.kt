package id.pati.parking

import id.pati.parking.data.local.SyncKind
import id.pati.parking.data.remote.ShiftDto
import id.pati.parking.data.remote.ShiftResult
import id.pati.parking.data.remote.SyncItemError
import id.pati.parking.data.remote.SyncItemResult
import id.pati.parking.data.remote.SyncResponse
import id.pati.parking.data.remote.TransactionDto
import id.pati.parking.sync.ApiOutcome
import id.pati.parking.sync.QueueItem
import id.pati.parking.sync.SyncApi
import id.pati.parking.sync.SyncEngine
import id.pati.parking.sync.SyncStore
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** In-memory queue with the same semantics as RoomSyncStore. */
private class FakeStore : SyncStore {
    data class Row(val item: QueueItem, var status: String = "PENDING", var ref: String? = null, var error: String? = null)

    val rows = mutableListOf<Row>()
    var balance = 0L
    private var nextId = 1L

    fun add(kind: String, uuid: String, shift: String? = null) {
        rows += Row(QueueItem(nextId++, kind, uuid, shift, """{"uuid":"$uuid"}""", 0))
    }

    fun status(kind: String, uuid: String) = rows.first { it.item.kind == kind && it.item.entityUuid == uuid }.status

    override suspend fun resetInterrupted() = rows.filter { it.status == "SYNCING" }.forEach { it.status = "PENDING" }
    override suspend fun pending(kind: String, limit: Int) = rows.filter { it.item.kind == kind && it.status == "PENDING" }.take(limit).map { it.item }
    override suspend fun isSynced(kind: String, entityUuid: String) = rows.any { it.item.kind == kind && it.item.entityUuid == entityUuid && it.status == "SYNCED" }
    override suspend fun unsyncedTransactions(shiftUuid: String) =
        rows.count { it.item.kind == SyncKind.TRANSACTION && it.item.shiftUuid == shiftUuid && it.status in setOf("PENDING", "SYNCING") }
    override suspend fun markSyncing(item: QueueItem) { row(item).status = "SYNCING" }
    override suspend fun markSynced(item: QueueItem, serverReference: String?, forcedClosed: Boolean) { row(item).apply { status = "SYNCED"; ref = serverReference } }
    override suspend fun markRetry(item: QueueItem, code: String, message: String) { row(item).apply { status = "PENDING"; error = code } }
    override suspend fun markFailed(item: QueueItem, code: String, message: String) { row(item).apply { status = "FAILED"; error = code } }
    override suspend fun saveCashBalance(balance: Long) { this.balance = balance }
    private fun row(item: QueueItem) = rows.first { it.item.id == item.id }
}

/** A server that behaves like the backend: idempotent, shift before transactions. */
private class FakeServer : SyncApi {
    val shifts = mutableSetOf<String>()
    val transactions = mutableSetOf<String>()
    var offline = false
    var rejectUuid: String? = null
    val calls = mutableListOf<String>()

    private fun uuid(json: String) = Regex("\"uuid\":\"([^\"]+)\"").find(json)!!.groupValues[1]

    override suspend fun startShift(payloadJson: String): ApiOutcome<ShiftResult> {
        calls += "start"
        if (offline) return ApiOutcome.NetworkError("offline")
        val u = uuid(payloadJson)
        val replayed = !shifts.add(u)
        return ApiOutcome.Success(ShiftResult(ShiftDto(u, "OPEN", startedAtDevice = "t"), replayed))
    }

    override suspend fun endShift(payloadJson: String): ApiOutcome<ShiftResult> {
        calls += "end"
        if (offline) return ApiOutcome.NetworkError("offline")
        return ApiOutcome.Success(ShiftResult(ShiftDto(uuid(payloadJson), "CLOSED", startedAtDevice = "t"), false))
    }

    override suspend fun syncTransactions(payloadJsons: List<String>): ApiOutcome<SyncResponse> {
        calls += "sync:${payloadJsons.size}"
        if (offline) return ApiOutcome.NetworkError("offline")
        val results = payloadJsons.mapIndexed { i, json ->
            val u = uuid(json)
            when {
                u == rejectUuid -> SyncItemResult(i, u, "REJECTED", null, SyncItemError("SYNC_CONFLICT", "beda data", retryable = false))
                transactions.add(u) -> SyncItemResult(i, u, "CREATED", TransactionDto(u, "TRX-$u", "COMPLETED", 2000))
                else -> SyncItemResult(i, u, "EXISTING", TransactionDto(u, "TRX-$u", "COMPLETED", 2000))
            }
        }
        return ApiOutcome.Success(SyncResponse(results, cashBalance = transactions.size * 2000L))
    }
}

class SyncEngineTest {
    private val store = FakeStore()
    private val server = FakeServer()
    private val engine = SyncEngine(server, store)

    @Test
    fun `syncs an offline shift, its transactions and its end in order (Scenario C)`() = runTest {
        store.add(SyncKind.SHIFT_START, "s1")
        store.add(SyncKind.TRANSACTION, "t1", "s1")
        store.add(SyncKind.TRANSACTION, "t2", "s1")
        store.add(SyncKind.SHIFT_END, "s1", "s1")

        val report = engine.runOnce()

        assertEquals(listOf("start", "sync:2", "end"), server.calls)
        assertEquals(4, report.synced)
        assertFalse(report.retryLater)
        assertEquals("TRX-t1", store.rows.first { it.item.entityUuid == "t1" }.ref)
        assertEquals(4000L, store.balance)
    }

    @Test
    fun `keeps everything pending while offline and loses nothing`() = runTest {
        server.offline = true
        store.add(SyncKind.SHIFT_START, "s1")
        store.add(SyncKind.TRANSACTION, "t1", "s1")

        val report = engine.runOnce()

        assertTrue(report.retryLater)
        assertEquals("PENDING", store.status(SyncKind.SHIFT_START, "s1"))
        assertEquals("PENDING", store.status(SyncKind.TRANSACTION, "t1"))
        assertEquals(2, store.rows.size)
    }

    @Test
    fun `never sends a transaction before its shift is on the server`() = runTest {
        store.add(SyncKind.TRANSACTION, "t1", "s-unsynced")

        val report = engine.runOnce()

        assertTrue(server.calls.isEmpty())
        assertTrue(report.retryLater)
        assertEquals("PENDING", store.status(SyncKind.TRANSACTION, "t1"))
    }

    @Test
    fun `a resend after a lost response does not duplicate (Scenario E)`() = runTest {
        store.add(SyncKind.SHIFT_START, "s1")
        store.add(SyncKind.TRANSACTION, "t1", "s1")
        server.shifts += "s1"
        server.transactions += "t1" // the server already stored it; the device never got the answer

        engine.runOnce()

        assertEquals("SYNCED", store.status(SyncKind.TRANSACTION, "t1"))
        assertEquals(1, server.transactions.size)
    }

    @Test
    fun `marks permanent rejections as failed and still syncs the rest`() = runTest {
        store.add(SyncKind.SHIFT_START, "s1")
        store.add(SyncKind.TRANSACTION, "t1", "s1")
        store.add(SyncKind.TRANSACTION, "bad", "s1")
        store.add(SyncKind.TRANSACTION, "t3", "s1")
        server.rejectUuid = "bad"

        val report = engine.runOnce()

        assertEquals("SYNCED", store.status(SyncKind.TRANSACTION, "t1"))
        assertEquals("FAILED", store.status(SyncKind.TRANSACTION, "bad"))
        assertEquals("SYNCED", store.status(SyncKind.TRANSACTION, "t3"))
        assertEquals(1, report.failed)
    }

    @Test
    fun `sends a shift end only after all its transactions are synced`() = runTest {
        store.add(SyncKind.SHIFT_START, "s1")
        store.add(SyncKind.SHIFT_END, "s1", "s1")
        store.add(SyncKind.TRANSACTION, "t1", "s-other")

        engine.runOnce()
        assertEquals("SYNCED", store.status(SyncKind.SHIFT_END, "s1"))

        store.add(SyncKind.SHIFT_START, "s2")
        store.rows.first { it.item.entityUuid == "s2" }.status = "SYNCED"
        store.add(SyncKind.TRANSACTION, "t2", "s2")
        store.add(SyncKind.SHIFT_END, "s2", "s2")
        server.offline = true
        engine.runOnce()
        assertEquals("PENDING", store.status(SyncKind.SHIFT_END, "s2"))
    }

    @Test
    fun `resets items interrupted mid-sync`() = runTest {
        store.add(SyncKind.SHIFT_START, "s1")
        store.rows.first().status = "SYNCING"

        engine.runOnce()

        assertEquals("SYNCED", store.status(SyncKind.SHIFT_START, "s1"))
    }

    @Test
    fun `sends large queues in batches of 50`() = runTest {
        store.add(SyncKind.SHIFT_START, "s1")
        repeat(120) { store.add(SyncKind.TRANSACTION, "t$it", "s1") }

        engine.runOnce()

        assertEquals(listOf("start", "sync:50", "sync:50", "sync:20"), server.calls)
        assertTrue(store.rows.filter { it.item.kind == SyncKind.TRANSACTION }.all { it.status == "SYNCED" })
    }
}
