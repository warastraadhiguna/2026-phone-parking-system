<?php

namespace App\Domain\ParkingLocation\Data;

use App\Domain\ParkingLocation\Enums\LocationType;

/** Editable attributes of a location (the code is set once at creation). */
final class LocationData
{
    public function __construct(
        public readonly string $name,
        public readonly string $address,
        public readonly string $latitude,
        public readonly string $longitude,
        public readonly int $geofenceRadiusM,
        public readonly LocationType $locationType,
        public readonly int $motorcycleCapacity,
        public readonly int $carCapacity,
    ) {}

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        return [
            'name' => trim($this->name),
            'address' => trim($this->address),
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'geofence_radius_m' => $this->geofenceRadiusM,
            'location_type' => $this->locationType,
            'motorcycle_capacity' => $this->motorcycleCapacity,
            'car_capacity' => $this->carCapacity,
        ];
    }
}
