package id.pati.parking

import id.pati.parking.data.remote.Bootstrap
import id.pati.parking.data.remote.CashTransactionPayload
import id.pati.parking.data.remote.Envelope
import id.pati.parking.data.remote.SyncResponse
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Test

/** Guards the wire contract against the docs/api contracts (samples copied from real backend responses). */
class DtoParsingTest {
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false; encodeDefaults = true }

    @Test
    fun `parses a bootstrap envelope`() {
        val text = """
            {"success":true,"data":{"generated_at":"2026-09-25T09:00:00+00:00","valid_until":"2026-09-28T09:00:00+00:00",
            "business_date":"2026-09-25","business_timezone":"Asia/Jakarta",
            "attendant":{"attendant_code":"JP-000001","name":"Demo","status":"ACTIVE"},
            "device":{"uuid":"d","status":"ACTIVE","status_label":"Aktif"},
            "assignment":{"id":1,"location_id":1,"effective_from":"2026-09-25","effective_until":null},
            "location":{"id":1,"location_code":"DEV-ALUN-01","name":"Alun","address":"Jl","latitude":"-6.7550000","longitude":"111.0380000","geofence_radius_m":50,"location_type":"ON_STREET","status":"ACTIVE"},
            "tariffs":[{"tariff_id":2,"vehicle_type":"CAR","amount":5000,"location_specific":false,"effective_from":"2026-09-25T11:38:59+00:00","effective_until":null}],
            "settings":{"offline_transaction_warning_hours":24,"max_open_shift_hours":16},"open_shift":null},
            "meta":{"request_id":"x"},"error":null}
        """.trimIndent()

        val envelope = json.decodeFromString(Envelope.serializer(), text)
        val bootstrap = json.decodeFromJsonElement(Bootstrap.serializer(), envelope.data!!)

        assertEquals("DEV-ALUN-01", bootstrap.location!!.locationCode)
        assertEquals(5000L, bootstrap.tariffs.single().amount)
        assertEquals(24, bootstrap.settings["offline_transaction_warning_hours"])
        assertNull(bootstrap.openShift)
    }

    @Test
    fun `parses per-item sync results including rejections`() {
        val text = """
            {"results":[{"index":0,"transaction_uuid":"a","result":"CREATED","transaction":{"transaction_uuid":"a","transaction_number":"TRX-1","status":"COMPLETED","charged_amount":2000,"expected_amount":2000,"review_flags":[]},"error":null},
            {"index":1,"transaction_uuid":"b","result":"REJECTED","transaction":null,"error":{"code":"SHIFT_NOT_ACTIVE","message":"x","retryable":true}}],
            "summary":{"CREATED":1,"EXISTING":0,"REJECTED":1},"cash_balance":2000}
        """.trimIndent()

        val response = json.decodeFromString(SyncResponse.serializer(), text)

        assertEquals("TRX-1", response.results[0].transaction!!.transactionNumber)
        assertEquals(true, response.results[1].error!!.retryable)
    }

    @Test
    fun `encodes the cash payload with the field names the server expects`() {
        val payload = CashTransactionPayload("u", "s", 7, "MOTORCYCLE", null, "CASH", 2000, 1, "2026-09-25T08:00:00+07:00", offlineCreated = true)
        val encoded = json.parseToJsonElement(json.encodeToString(CashTransactionPayload.serializer(), payload)).jsonObject

        assertEquals(setOf("transaction_uuid", "shift_uuid", "sync_sequence", "vehicle_type", "payment_method", "charged_amount", "tariff_id", "transaction_time_device", "mock_location", "offline_created"), encoded.keys)
        assertFalse(encoded.containsKey("vehicle_plate")) // explicitNulls = false: absent, not null
    }
}
