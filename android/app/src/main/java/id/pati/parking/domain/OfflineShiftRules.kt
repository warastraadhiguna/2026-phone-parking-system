package id.pati.parking.domain

import id.pati.parking.data.remote.Bootstrap
import java.time.LocalDate
import java.time.OffsetDateTime
import java.time.ZoneId

/**
 * ADR-0008: a shift may be started without connectivity only if ALL preconditions hold.
 * Returns the first violated rule (Indonesian message for the attendant), or null if allowed.
 */
object OfflineShiftRules {
    fun violation(bootstrap: Bootstrap?, hasLoggedInBefore: Boolean, now: OffsetDateTime): String? {
        // 5. required bootstrap exists locally
        if (bootstrap == null) return "Belum ada data konfigurasi. Hubungkan ke internet sekali untuk mengunduhnya."
        // 1. device approved by the server
        if (bootstrap.device.status != "ACTIVE") return "Perangkat belum disetujui admin."
        // 2. attendant authenticated successfully before
        if (!hasLoggedInBefore) return "Anda harus login online terlebih dahulu."
        // 6. local configuration not older than the allowed offline age
        if (now.isAfter(OffsetDateTime.parse(bootstrap.validUntil))) {
            return "Data konfigurasi sudah kedaluwarsa. Hubungkan ke internet untuk memperbarui."
        }
        // 3. assignment cached
        val assignment = bootstrap.assignment ?: return "Tidak ada penugasan tersimpan."
        if (bootstrap.location == null) return "Data lokasi penugasan tidak tersimpan."
        // 4. assignment valid today (business timezone, inclusive dates)
        val today = now.atZoneSameInstant(ZoneId.of(bootstrap.businessTimezone)).toLocalDate()
        val from = LocalDate.parse(assignment.effectiveFrom)
        val until = assignment.effectiveUntil?.let(LocalDate::parse)
        if (today.isBefore(from) || (until != null && today.isAfter(until))) {
            return "Penugasan tersimpan tidak berlaku hari ini."
        }
        // 5. tariffs present
        if (bootstrap.tariffs.isEmpty()) return "Data tarif tidak tersimpan."

        return null
    }
}
