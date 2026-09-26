package id.pati.parking

import id.pati.parking.data.remote.TariffDto
import id.pati.parking.domain.Price
import id.pati.parking.domain.TariffCalculator
import id.pati.parking.domain.VehicleType
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test
import java.time.OffsetDateTime

class TariffCalculatorTest {
    private fun t(id: Long, vehicle: String, amount: Long, specific: Boolean, from: String, until: String? = null) =
        TariffDto(id, vehicle, amount, specific, from, until)

    private val schedule = listOf(
        t(1, "MOTORCYCLE", 2000, false, "2026-09-01T00:00:00+07:00", "2026-10-01T00:00:00+07:00"),
        t(2, "MOTORCYCLE", 3000, false, "2026-10-01T00:00:00+07:00"),
        t(3, "CAR", 5000, false, "2026-09-01T00:00:00+07:00"),
        t(4, "CAR", 4000, true, "2026-09-15T00:00:00+07:00"),
    )

    private fun at(s: String) = OffsetDateTime.parse(s)

    @Test
    fun `uses the tariff covering the moment`() {
        assertEquals(Price(1, 2000), TariffCalculator.priceFor(VehicleType.MOTORCYCLE, at("2026-09-30T23:59:59+07:00"), schedule))
        assertEquals(Price(2, 3000), TariffCalculator.priceFor(VehicleType.MOTORCYCLE, at("2026-10-01T00:00:00+07:00"), schedule))
    }

    @Test
    fun `compares instants across time zones`() {
        // 2026-09-30T17:00Z == 2026-10-01T00:00+07:00 → the new tariff
        assertEquals(Price(2, 3000), TariffCalculator.priceFor(VehicleType.MOTORCYCLE, at("2026-09-30T17:00:00Z"), schedule))
    }

    @Test
    fun `prefers a location-specific tariff`() {
        assertEquals(Price(3, 5000), TariffCalculator.priceFor(VehicleType.CAR, at("2026-09-10T10:00:00+07:00"), schedule))
        assertEquals(Price(4, 4000), TariffCalculator.priceFor(VehicleType.CAR, at("2026-09-20T10:00:00+07:00"), schedule))
    }

    @Test
    fun `returns null when nothing applies`() {
        assertNull(TariffCalculator.priceFor(VehicleType.OTHER, at("2026-09-20T10:00:00+07:00"), schedule))
        assertNull(TariffCalculator.priceFor(VehicleType.MOTORCYCLE, at("2026-08-31T23:59:59+07:00"), schedule))
    }
}
