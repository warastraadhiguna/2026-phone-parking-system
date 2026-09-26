<?php

namespace App\Domain\ParkingLocation\Data;

use App\Domain\ParkingLocation\Enums\GeofenceResult;

final class GeofenceCheck
{
    public function __construct(
        public readonly GeofenceResult $result,
        public readonly ?int $distanceM,
    ) {}
}
