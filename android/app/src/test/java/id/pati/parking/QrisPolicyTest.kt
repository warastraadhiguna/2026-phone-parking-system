package id.pati.parking

import id.pati.parking.data.remote.CashSummaryDto
import id.pati.parking.data.remote.QrisResult
import id.pati.parking.domain.QrisPolicy
import kotlinx.serialization.json.Json
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import java.time.OffsetDateTime

class QrisPolicyTest {
    private val expires = OffsetDateTime.parse("2026-09-25T08:15:00+07:00")

    @Test
    fun `stops polling only on a final status or well after expiry`() {
        assertTrue(QrisPolicy.shouldPoll("PENDING", expires, expires.minusMinutes(5)))
        // The provider may still confirm shortly after expiry.
        assertTrue(QrisPolicy.shouldPoll("PENDING", expires, expires.plusSeconds(60)))
        assertFalse(QrisPolicy.shouldPoll("PENDING", expires, expires.plusSeconds(QrisPolicy.GRACE_SECONDS + 1)))
        listOf("PAID", "EXPIRED", "FAILED", "CANCELLED").forEach {
            assertTrue(QrisPolicy.isFinal(it))
            assertFalse(QrisPolicy.shouldPoll(it, expires, expires.minusMinutes(5)))
        }
        assertFalse(QrisPolicy.isFinal("CREATED"))
    }

    @Test
    fun `parses the QRIS response of docs-api-payments`() {
        val json = Json { ignoreUnknownKeys = true; explicitNulls = false }
        val body = """
            {"transaction":{"transaction_uuid":"t-1","transaction_number":"TRX-260925-00000009","status":"WAITING_PAYMENT",
             "payment_method":"QRIS","charged_amount":2000,"expected_amount":2000,"review_flags":[]},
             "payment":{"payment_uuid":"p-1","status":"PENDING","method":"QRIS","provider":"MIDTRANS","amount":2000,
             "qr_string":"00020101021226","qr_image_url":null,"expires_at":"2026-09-25T08:15:00+07:00","paid_at":null},
             "replayed":false}
        """.trimIndent()

        val result = json.decodeFromString(QrisResult.serializer(), body)
        assertEquals("PENDING", result.payment.status)
        assertEquals(2000L, result.payment.amount)
        assertEquals("00020101021226", result.payment.qrString)
        assertNull(result.payment.qrImageUrl)
        assertEquals("TRX-260925-00000009", result.transaction.transactionNumber)
    }

    @Test
    fun `parses the cash summary of docs-api-settlements`() {
        val json = Json { ignoreUnknownKeys = true; explicitNulls = false }
        val body = """
            {"cash_balance":1000,"total":{"collected":6000,"deposited":5000,"outstanding":1000,"cash_in":6000,"reversals":0,"adjustments":0},
             "today":{"date":"2026-09-25","collected":6000,"deposited":5000,"outstanding":1000,"cash_in":6000,"reversals":0,"adjustments":0},
             "open_shift":null,
             "pending_settlement":{"settlement_uuid":"s-1","settlement_number":"STL-260925-00000001","status":"SUBMITTED","status_label":"Menunggu verifikasi",
               "amount":1000,"verified_amount":null,"submitted_at":"2026-09-25T10:00:00+07:00"},
             "as_of":"2026-09-25T10:00:01+07:00"}
        """.trimIndent()

        val summary = json.decodeFromString(CashSummaryDto.serializer(), body)
        assertEquals(1000L, summary.total.outstanding)
        assertEquals("SUBMITTED", summary.pendingSettlement?.status)
    }
}
