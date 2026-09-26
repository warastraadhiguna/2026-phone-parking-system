package id.pati.parking.domain

import id.pati.parking.data.remote.TariffDto
import java.time.OffsetDateTime

/** Vehicle types accepted by the backend (master doc §9). */
enum class VehicleType(val label: String) {
    MOTORCYCLE("Motor"),
    CAR("Mobil"),
    OTHER("Lainnya"),
}

/** The tariff the device charges, with the id reported to the server as `tariff_id`. */
data class Price(val tariffId: Long, val amount: Long)

/**
 * Prices a vehicle from the cached bootstrap tariff schedule (docs/api/shifts.md):
 * at time t, use the location-specific row covering t, otherwise the location-type row.
 * "Covering" means effective_from ≤ t < effective_until (null = open-ended).
 * Returns null when no tariff applies — the app must then refuse to charge.
 */
object TariffCalculator {
    fun priceFor(vehicle: VehicleType, at: OffsetDateTime, schedule: List<TariffDto>): Price? {
        val covering = schedule.filter { it.vehicleType == vehicle.name && covers(it, at) }
        val chosen = covering.firstOrNull { it.locationSpecific } ?: covering.firstOrNull { !it.locationSpecific }

        return chosen?.let { Price(it.tariffId, it.amount) }
    }

    private fun covers(t: TariffDto, at: OffsetDateTime): Boolean {
        val from = OffsetDateTime.parse(t.effectiveFrom)
        val until = t.effectiveUntil?.let(OffsetDateTime::parse)

        return !at.isBefore(from) && (until == null || at.isBefore(until))
    }
}
