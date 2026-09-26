<?php

namespace App\Support\Geo;

/** A position reported by the device. Any part may be missing (no fix, permission denied). */
final class GpsFix
{
    public function __construct(
        public readonly ?float $latitude,
        public readonly ?float $longitude,
        public readonly ?float $accuracyM,
        public readonly bool $mockLocation = false,
    ) {}

    public function hasPosition(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /** Great-circle distance in metres (Haversine). */
    public static function distanceM(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6_371_000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }
}
