<?php

use App\Domain\ParkingLocation\Enums\GeofenceResult;
use App\Domain\ParkingLocation\Models\ParkingLocation;
use App\Domain\ParkingLocation\Services\Geofence;
use App\Support\Geo\GpsFix;

function locationAt(float $lat, float $lng, int $radius): ParkingLocation
{
    return (new ParkingLocation)->forceFill(['latitude' => (string) $lat, 'longitude' => (string) $lng, 'geofence_radius_m' => $radius]);
}

it('computes great-circle distances', function () {
    // 0.001° latitude ≈ 111 m
    expect(GpsFix::distanceM(-6.755, 111.038, -6.756, 111.038))->toBeGreaterThan(110)->toBeLessThan(112)
        ->and(GpsFix::distanceM(-6.755, 111.038, -6.755, 111.038))->toBe(0.0);
});

it('classifies positions against the geofence', function (?float $lat, ?float $acc, GeofenceResult $expected) {
    $location = locationAt(-6.755, 111.038, 50);
    $check = (new Geofence)->check($location, new GpsFix($lat, $lat === null ? null : 111.038, $acc), 100);

    expect($check->result)->toBe($expected);
})->with([
    'at the point' => [-6.755, 5.0, GeofenceResult::INSIDE],
    '~44 m, inside radius' => [-6.7554, 5.0, GeofenceResult::INSIDE],
    '~67 m, within radius + accuracy' => [-6.7556, 30.0, GeofenceResult::UNKNOWN],
    '~67 m, outside radius + accuracy' => [-6.7556, 5.0, GeofenceResult::OUTSIDE],
    '~1.1 km away' => [-6.765, 10.0, GeofenceResult::OUTSIDE],
    'accuracy worse than allowed' => [-6.765, 150.0, GeofenceResult::UNKNOWN],
    'accuracy unknown, far away' => [-6.765, null, GeofenceResult::OUTSIDE],
    'accuracy unknown, near the edge' => [-6.7556, null, GeofenceResult::UNKNOWN],
    'no position' => [null, null, GeofenceResult::UNKNOWN],
]);

it('reports the rounded distance', function () {
    $check = (new Geofence)->check(locationAt(-6.755, 111.038, 50), new GpsFix(-6.756, 111.038, 5.0), 100);

    expect($check->distanceM)->toBe(111);
});
