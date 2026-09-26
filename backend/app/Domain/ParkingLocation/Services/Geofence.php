<?php

namespace App\Domain\ParkingLocation\Services;

use App\Domain\ParkingLocation\Data\GeofenceCheck;
use App\Domain\ParkingLocation\Enums\GeofenceResult;
use App\Domain\ParkingLocation\Models\ParkingLocation;
use App\Support\Geo\GpsFix;

/**
 * Classifies a GPS fix against a location's geofence (master doc §24).
 *
 * - UNKNOWN: no position, or accuracy worse than the configured maximum.
 * - INSIDE:  distance ≤ radius.
 * - OUTSIDE: distance > radius + accuracy (outside even allowing for GPS error: "significant").
 * - UNKNOWN: in between (could be either).
 *
 * The result is a signal for review, never a reason to drop a record.
 */
final class Geofence
{
    public function check(ParkingLocation $location, GpsFix $fix, int $maxAccuracyM): GeofenceCheck
    {
        if (! $fix->hasPosition()) {
            return new GeofenceCheck(GeofenceResult::UNKNOWN, null);
        }

        $distance = GpsFix::distanceM((float) $location->latitude, (float) $location->longitude, (float) $fix->latitude, (float) $fix->longitude);
        $accuracy = max(0.0, $fix->accuracyM ?? (float) $maxAccuracyM);
        $rounded = (int) round($distance);

        if ($fix->accuracyM !== null && $fix->accuracyM > $maxAccuracyM) {
            return new GeofenceCheck(GeofenceResult::UNKNOWN, $rounded);
        }

        return match (true) {
            $distance <= $location->geofence_radius_m => new GeofenceCheck(GeofenceResult::INSIDE, $rounded),
            $distance > $location->geofence_radius_m + $accuracy => new GeofenceCheck(GeofenceResult::OUTSIDE, $rounded),
            default => new GeofenceCheck(GeofenceResult::UNKNOWN, $rounded),
        };
    }
}
