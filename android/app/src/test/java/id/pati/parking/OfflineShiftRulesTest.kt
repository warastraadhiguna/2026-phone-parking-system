package id.pati.parking

import id.pati.parking.data.remote.AssignmentDto
import id.pati.parking.data.remote.AttendantDto
import id.pati.parking.data.remote.Bootstrap
import id.pati.parking.data.remote.DeviceDto
import id.pati.parking.data.remote.LocationDto
import id.pati.parking.data.remote.TariffDto
import id.pati.parking.domain.OfflineShiftRules
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test
import java.time.OffsetDateTime

class OfflineShiftRulesTest {
    private val now = OffsetDateTime.parse("2026-09-25T08:00:00+07:00")

    private fun bootstrap(
        device: String = "ACTIVE",
        validUntil: String = "2026-09-27T00:00:00+07:00",
        assignment: AssignmentDto? = AssignmentDto(1, 1, "2026-09-20", null),
        tariffs: List<TariffDto> = listOf(TariffDto(1, "MOTORCYCLE", 2000, false, "2026-09-01T00:00:00+07:00")),
    ) = Bootstrap(
        generatedAt = "2026-09-24T08:00:00+07:00",
        validUntil = validUntil,
        businessDate = "2026-09-24",
        businessTimezone = "Asia/Jakarta",
        attendant = AttendantDto("JP-000001", "Uji", "ACTIVE"),
        device = DeviceDto("d", device),
        assignment = assignment,
        location = LocationDto(1, "LOC-1", "Lokasi", null, "-6.755", "111.038", 50, "ON_STREET", "ACTIVE"),
        tariffs = tariffs,
    )

    @Test
    fun `allows an offline shift when all six preconditions hold`() {
        assertNull(OfflineShiftRules.violation(bootstrap(), hasLoggedInBefore = true, now = now))
    }

    @Test
    fun `refuses each violated precondition`() {
        assertEquals("Belum ada data konfigurasi. Hubungkan ke internet sekali untuk mengunduhnya.", OfflineShiftRules.violation(null, true, now))
        assertEquals("Perangkat belum disetujui admin.", OfflineShiftRules.violation(bootstrap(device = "PENDING_APPROVAL"), true, now))
        assertEquals("Anda harus login online terlebih dahulu.", OfflineShiftRules.violation(bootstrap(), false, now))
        assertEquals(
            "Data konfigurasi sudah kedaluwarsa. Hubungkan ke internet untuk memperbarui.",
            OfflineShiftRules.violation(bootstrap(validUntil = "2026-09-25T07:59:59+07:00"), true, now),
        )
        assertEquals("Tidak ada penugasan tersimpan.", OfflineShiftRules.violation(bootstrap(assignment = null), true, now))
        assertEquals("Data tarif tidak tersimpan.", OfflineShiftRules.violation(bootstrap(tariffs = emptyList()), true, now))
    }

    @Test
    fun `checks the assignment period on the WIB calendar day`() {
        val ended = bootstrap(assignment = AssignmentDto(1, 1, "2026-09-20", "2026-09-24"))
        assertEquals("Penugasan tersimpan tidak berlaku hari ini.", OfflineShiftRules.violation(ended, true, now))

        // 2026-09-24T18:00Z is already 2026-09-25 in WIB: the assignment starting on the 25th applies.
        val startsToday = bootstrap(assignment = AssignmentDto(1, 1, "2026-09-25", null))
        assertNull(OfflineShiftRules.violation(startsToday, true, OffsetDateTime.parse("2026-09-24T18:00:00Z")))
    }
}
