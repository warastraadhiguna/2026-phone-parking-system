<?php

namespace App\Domain\Tariff\Data;

use App\Domain\ParkingLocation\Enums\LocationType;
use App\Domain\Tariff\Enums\VehicleType;
use Carbon\CarbonImmutable;

final class TariffData
{
    public function __construct(
        public readonly VehicleType $vehicleType,
        public readonly LocationType $locationType,
        public readonly ?int $locationId,
        public readonly int $amount,
        public readonly CarbonImmutable $effectiveFrom,
        public readonly string $regulationReference,
    ) {}

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        return [
            'vehicle_type' => $this->vehicleType,
            'location_type' => $this->locationType,
            'location_id' => $this->locationId,
            'amount' => $this->amount,
            'effective_from' => $this->effectiveFrom,
            'regulation_reference' => trim($this->regulationReference),
        ];
    }
}
