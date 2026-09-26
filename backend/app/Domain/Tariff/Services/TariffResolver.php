<?php

namespace App\Domain\Tariff\Services;

use App\Domain\ParkingLocation\Models\ParkingLocation;
use App\Domain\Tariff\Enums\TariffStatus;
use App\Domain\Tariff\Enums\VehicleType;
use App\Domain\Tariff\Models\Tariff;
use App\Support\Errors\ApiException;
use App\Support\Errors\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which approved tariff applies to a vehicle at a location at an instant.
 * A location-specific tariff wins over the location-type tariff.
 */
final class TariffResolver
{
    /** @throws ApiException TARIFF_NOT_FOUND */
    public function resolve(VehicleType $vehicleType, ParkingLocation $location, CarbonImmutable $at): Tariff
    {
        return $this->find($vehicleType, $location, $at)
            ?? throw new ApiException(ErrorCode::TARIFF_NOT_FOUND, "Tidak ada tarif berlaku untuk {$vehicleType->label()} di lokasi {$location->location_code}.");
    }

    public function find(VehicleType $vehicleType, ParkingLocation $location, CarbonImmutable $at): ?Tariff
    {
        $inEffect = fn (): Builder => Tariff::query()
            ->where('status', TariffStatus::APPROVED->value)
            ->where('vehicle_type', $vehicleType->value)
            ->where('location_type', $location->location_type->value)
            ->where('effective_from', '<=', $at)
            ->where(fn (Builder $q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $at));

        return $inEffect()->where('location_id', $location->id)->first()
            ?? $inEffect()->whereNull('location_id')->first();
    }

    /**
     * Approved tariffs relevant to a location in a time window (current and upcoming), for the
     * device to price offline. Rule for the device: at time t, use the location-specific row
     * covering t if any, otherwise the location-type row covering t.
     *
     * @return list<Tariff>
     */
    public function scheduleFor(ParkingLocation $location, CarbonImmutable $from, CarbonImmutable $until): array
    {
        return array_values(Tariff::query()
            ->where('status', TariffStatus::APPROVED->value)
            ->where('location_type', $location->location_type->value)
            ->where(fn (Builder $q) => $q->whereNull('location_id')->orWhere('location_id', $location->id))
            ->where('effective_from', '<', $until)
            ->where(fn (Builder $q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $from))
            ->orderBy('vehicle_type')->orderBy('effective_from')
            ->get()
            ->all());
    }

    /**
     * All tariffs in effect at a location now, one per vehicle type (for the mobile bootstrap).
     *
     * @return array<string, Tariff>
     */
    public function allFor(ParkingLocation $location, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();
        $result = [];
        foreach (VehicleType::cases() as $type) {
            $tariff = $this->find($type, $location, $at);
            if ($tariff !== null) {
                $result[$type->value] = $tariff;
            }
        }

        return $result;
    }
}
