package id.pati.parking

import id.pati.parking.data.local.SyncKind
import id.pati.parking.data.prefs.TokenStore
import id.pati.parking.data.remote.ApiClient
import id.pati.parking.data.remote.CashTransactionPayload
import id.pati.parking.data.remote.EndShiftPayload
import id.pati.parking.data.remote.SettlementPayload
import id.pati.parking.data.remote.StartShiftPayload
import id.pati.parking.data.remote.TokenPair
import id.pati.parking.domain.TariffCalculator
import id.pati.parking.domain.VehicleType
import id.pati.parking.sync.ApiOutcome
import id.pati.parking.sync.QueueItem
import id.pati.parking.sync.SyncEngine
import id.pati.parking.sync.SyncStore
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Assume.assumeTrue
import org.junit.Test
import java.time.OffsetDateTime
import java.time.temporal.ChronoUnit
import java.util.UUID

/**
 * End-to-end against a running backend (opt-in): the real ApiClient and SyncEngine talk to the
 * real API, simulating a device that worked offline and comes back online (Scenarios C and E).
 *
 * Run: PATI_E2E_BASE_URL=http://localhost:8080/ PATI_E2E_USERNAME=jp-000001 PATI_E2E_PASSWORD=… \
 *      PATI_E2E_DEVICE_UUID=<an ACTIVE device of that attendant> gradlew testDebugUnitTest
 * Skipped when PATI_E2E_BASE_URL is not set.
 */
class BackendIntegrationTest {
    private class MemoryTokens : TokenStore {
        var tokens: TokenPair? = null
        override fun save(tokens: TokenPair) { this.tokens = tokens }
        override fun load() = tokens
        override fun clear() { tokens = null }
    }

    private class MemoryStore : SyncStore {
        data class Row(val item: QueueItem, var status: String = "PENDING", var ref: String? = null, var error: String? = null)
        val rows = mutableListOf<Row>()
        var balance = -1L
        fun add(kind: String, uuid: String, shift: String?, json: String) { rows += Row(QueueItem(rows.size + 1L, kind, uuid, shift, json, 0)) }
        override suspend fun resetInterrupted() = rows.filter { it.status == "SYNCING" }.forEach { it.status = "PENDING" }
        override suspend fun pending(kind: String, limit: Int) = rows.filter { it.item.kind == kind && it.status == "PENDING" }.take(limit).map { it.item }
        override suspend fun isSynced(kind: String, entityUuid: String) = rows.any { it.item.kind == kind && it.item.entityUuid == entityUuid && it.status == "SYNCED" }
        override suspend fun unsyncedTransactions(shiftUuid: String) = rows.count { it.item.kind == SyncKind.TRANSACTION && it.item.shiftUuid == shiftUuid && it.status != "SYNCED" && it.status != "FAILED" }
        override suspend fun markSyncing(item: QueueItem) { row(item).status = "SYNCING" }
        override suspend fun markSynced(item: QueueItem, serverReference: String?, forcedClosed: Boolean) { row(item).apply { status = "SYNCED"; ref = serverReference } }
        override suspend fun markRetry(item: QueueItem, code: String, message: String) { row(item).apply { status = "PENDING"; error = "$code $message" } }
        override suspend fun markFailed(item: QueueItem, code: String, message: String) { row(item).apply { status = "FAILED"; error = "$code $message" } }
        override suspend fun saveCashBalance(balance: Long) { this.balance = balance }
        private fun row(item: QueueItem) = rows.first { it.item.id == item.id }
    }

    @Test
    fun `an offline session syncs completely and idempotently`() = runTest {
        val baseUrl = System.getenv("PATI_E2E_BASE_URL")
        assumeTrue("PATI_E2E_BASE_URL not set: skipping backend integration test", !baseUrl.isNullOrBlank())
        val deviceUuid = System.getenv("PATI_E2E_DEVICE_UUID")!!

        val tokens = MemoryTokens()
        val api = ApiClient(baseUrl!!, tokens) { deviceUuid }
        val login = api.login(System.getenv("PATI_E2E_USERNAME")!!, System.getenv("PATI_E2E_PASSWORD")!!, "JVM", "test", "e2e")
        assertTrue("login failed: $login", login is ApiOutcome.Success)

        closeOpenShift(api)
        val bootstrap = (api.bootstrap() as ApiOutcome.Success).data
        assertNotNull("no assignment for this attendant today", bootstrap.location)
        val location = bootstrap.location!!
        val now = OffsetDateTime.now().truncatedTo(ChronoUnit.SECONDS)
        val price = TariffCalculator.priceFor(VehicleType.MOTORCYCLE, now, bootstrap.tariffs)
        assertNotNull("no motorcycle tariff", price)
        price!!

        // Work recorded "offline": a shift, three cash transactions, the shift end.
        val store = MemoryStore()
        val shiftUuid = UUID.randomUUID().toString()
        store.add(SyncKind.SHIFT_START, shiftUuid, null, api.json.encodeToString(StartShiftPayload.serializer(),
            StartShiftPayload(shiftUuid, location.id, now.minusMinutes(30).toString(), -6.7551, 111.038, 8.0, false, offlineCreated = true)))
        val base = System.currentTimeMillis() * 10
        repeat(3) { i ->
            val tx = UUID.randomUUID().toString()
            store.add(SyncKind.TRANSACTION, tx, shiftUuid, api.json.encodeToString(CashTransactionPayload.serializer(),
                CashTransactionPayload(tx, shiftUuid, base + i, "MOTORCYCLE", null, "CASH", price.amount, price.tariffId,
                    now.minusMinutes(20L - i).toString(), -6.7551, 111.038, 8.0, false, offlineCreated = true)))
        }
        store.add(SyncKind.SHIFT_END, shiftUuid, shiftUuid, api.json.encodeToString(EndShiftPayload.serializer(),
            EndShiftPayload(shiftUuid, now.toString(), -6.7551, 111.038, 8.0, false)))

        val first = SyncEngine(api, store).runOnce()
        assertEquals("rows: ${store.rows}", 5, first.synced)
        assertTrue(store.rows.all { it.status == "SYNCED" })
        assertTrue(store.rows.filter { it.item.kind == SyncKind.TRANSACTION }.all { it.ref!!.startsWith("TRX-") })

        // Lost-response replay: resend everything; the server must not create anything new.
        store.rows.forEach { it.status = "PENDING" }
        val balanceBefore = store.balance
        val second = SyncEngine(api, store).runOnce()
        assertEquals(5, second.synced)
        assertEquals(balanceBefore, store.balance)
    }

    /**
     * QRIS against the running backend: the QR is issued, the app polls, the attendant withdraws it.
     * The status always comes from the server (fake gateway locally, Midtrans sandbox when configured).
     */
    @Test
    fun `a QRIS payment is issued, polled and withdrawn`() = runTest {
        val baseUrl = System.getenv("PATI_E2E_BASE_URL")
        assumeTrue("PATI_E2E_BASE_URL not set: skipping backend integration test", !baseUrl.isNullOrBlank())
        val deviceUuid = System.getenv("PATI_E2E_DEVICE_UUID")!!
        val api = ApiClient(baseUrl!!, MemoryTokens()) { deviceUuid }
        assertTrue(api.login(System.getenv("PATI_E2E_USERNAME")!!, System.getenv("PATI_E2E_PASSWORD")!!, "JVM", "test", "e2e") is ApiOutcome.Success)

        val bootstrap = (api.bootstrap() as ApiOutcome.Success).data
        val location = bootstrap.location!!
        val now = OffsetDateTime.now().truncatedTo(ChronoUnit.SECONDS)
        val price = TariffCalculator.priceFor(VehicleType.MOTORCYCLE, now, bootstrap.tariffs)!!

        // QRIS needs an OPEN shift on this device: open one online (closed again at the end).
        closeOpenShift(api)
        val shiftUuid = UUID.randomUUID().toString()
        val started = api.startShift(api.json.encodeToString(StartShiftPayload.serializer(),
            StartShiftPayload(shiftUuid, location.id, now.toString(), -6.7551, 111.038, 8.0, false, offlineCreated = false)))
        assertTrue("shift start: $started", started is ApiOutcome.Success)

        val tx = UUID.randomUUID().toString()
        val payload = api.json.encodeToString(CashTransactionPayload.serializer(),
            CashTransactionPayload(tx, shiftUuid, System.currentTimeMillis() * 10 + 7, "MOTORCYCLE", null, "QRIS", price.amount, price.tariffId,
                now.toString(), -6.7551, 111.038, 8.0, false, offlineCreated = false))

        val created = api.createQris(payload)
        assertTrue("create: $created", created is ApiOutcome.Success)
        val payment = (created as ApiOutcome.Success).data.payment
        assertEquals("PENDING", payment.status)
        assertNotNull(payment.qrString ?: payment.qrImageUrl)

        // Retry after a lost response returns the same payment.
        assertEquals(payment.paymentUuid, (api.createQris(payload) as ApiOutcome.Success).data.payment.paymentUuid)

        assertEquals("PENDING", (api.payment(payment.paymentUuid) as ApiOutcome.Success).data.payment.status)
        val cancelled = (api.cancelPayment(payment.paymentUuid) as ApiOutcome.Success).data
        assertEquals("CANCELLED", cancelled.payment.status)
        assertEquals("CANCELLED", cancelled.transaction.status)

        closeOpenShift(api)
    }

    /** Settlement against the running backend: summary, submit (idempotent), withdraw. */
    @Test
    fun `a cash deposit is declared idempotently and can be withdrawn`() = runTest {
        val baseUrl = System.getenv("PATI_E2E_BASE_URL")
        assumeTrue("PATI_E2E_BASE_URL not set: skipping backend integration test", !baseUrl.isNullOrBlank())
        val deviceUuid = System.getenv("PATI_E2E_DEVICE_UUID")!!
        val api = ApiClient(baseUrl!!, MemoryTokens()) { deviceUuid }
        assertTrue(api.login(System.getenv("PATI_E2E_USERNAME")!!, System.getenv("PATI_E2E_PASSWORD")!!, "JVM", "test", "e2e") is ApiOutcome.Success)

        val summary = (api.cashSummary() as ApiOutcome.Success).data
        assertEquals(summary.total.collected - summary.total.deposited, summary.total.outstanding)
        assumeTrue("attendant holds no cash", summary.cashBalance > 0)
        summary.pendingSettlement?.let { api.cancelSettlement(it.settlementUuid) }

        val payload = api.json.encodeToString(SettlementPayload.serializer(), SettlementPayload(UUID.randomUUID().toString(), minOf(1000L, summary.cashBalance)))
        val first = (api.submitSettlement(payload) as ApiOutcome.Success).data
        assertEquals("SUBMITTED", first.settlement.status)
        val again = (api.submitSettlement(payload) as ApiOutcome.Success).data
        assertTrue(again.replayed)
        assertEquals(first.settlement.settlementNumber, again.settlement.settlementNumber)
        assertEquals(summary.cashBalance, again.cashBalance)

        assertEquals("CANCELLED", (api.cancelSettlement(first.settlement.settlementUuid) as ApiOutcome.Success).data.settlement.status)
    }

    /** The tests need an attendant without an open shift; end one left by earlier runs. */
    private suspend fun closeOpenShift(api: ApiClient) {
        val open = (api.bootstrap() as ApiOutcome.Success).data.openShift ?: return
        val ended = api.endShift(api.json.encodeToString(EndShiftPayload.serializer(),
            EndShiftPayload(open.shiftUuid, OffsetDateTime.now().truncatedTo(ChronoUnit.SECONDS).toString(), -6.7551, 111.038, 8.0, false)))
        assertTrue("closing the open shift: $ended", ended is ApiOutcome.Success)
    }
}
