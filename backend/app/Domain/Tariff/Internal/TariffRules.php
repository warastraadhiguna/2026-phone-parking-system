<?php

namespace App\Domain\Tariff\Internal;

use App\Domain\ParkingLocation\Models\ParkingLocation;
use App\Domain\Tariff\Data\TariffData;
use App\Support\Errors\RuleViolation;

final class TariffRules
{
    /** A location-specific tariff must match that location's type. */
    public function assertScopeConsistent(TariffData $data): void
    {
        if ($data->locationId === null) {
            return;
        }

        $location = ParkingLocation::query()->find($data->locationId);
        if ($location === null) {
            throw new RuleViolation('location_id', 'Lokasi tidak ditemukan.');
        }
        if ($location->location_type !== $data->locationType) {
            throw new RuleViolation('location_id', "Lokasi {$location->location_code} bertipe {$location->location_type->label()}, bukan {$data->locationType->label()}.");
        }
    }
}
