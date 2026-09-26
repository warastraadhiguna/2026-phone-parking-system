<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Enums\Role;
use App\Domain\ParkingLocation\Enums\LocationStatus;
use App\Domain\ParkingLocation\Models\ParkingLocation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    syncRoles();
    $this->withoutVite();
    $this->dishub = staffUser(Role::DISHUB_ADMIN);
});

/** @return array<string, mixed> */
function locationPayload(array $overrides = []): array
{
    return [
        'location_code' => 'pti-alun-01',
        'name' => 'Alun-Alun Pati',
        'address' => 'Jl. Alun-Alun',
        'latitude' => '-6.7550000',
        'longitude' => '111.0380000',
        'geofence_radius_m' => 50,
        'location_type' => 'ON_STREET',
        'motorcycle_capacity' => 40,
        'car_capacity' => 10,
        ...$overrides,
    ];
}

it('creates a location with an upper-case immutable code and audits it', function () {
    $this->actingAs($this->dishub)->post('/locations', locationPayload())->assertRedirect()->assertSessionHas('success');

    $location = ParkingLocation::sole();
    expect($location->location_code)->toBe('PTI-ALUN-01')
        ->and($location->status)->toBe(LocationStatus::ACTIVE)
        ->and($location->geofence_radius_m)->toBe(50);

    expect(auditOf(AuditAction::LOCATION_CREATED)->sole()->metadata['location_code'])->toBe('PTI-ALUN-01');
});

it('validates location input', function (array $overrides, string $field) {
    makeLocation(['location_code' => 'TAKEN-01']);

    $this->actingAs($this->dishub)->post('/locations', locationPayload($overrides))->assertSessionHasErrors($field);
})->with([
    'duplicate code' => [['location_code' => 'taken-01'], 'location_code'],
    'bad code' => [['location_code' => 'a b'], 'location_code'],
    'latitude out of range' => [['latitude' => '-91'], 'latitude'],
    'radius too small' => [['geofence_radius_m' => 2], 'geofence_radius_m'],
    'radius too large' => [['geofence_radius_m' => 5000], 'geofence_radius_m'],
    'unknown type' => [['location_type' => 'ROOFTOP'], 'location_type'],
    'negative capacity' => [['car_capacity' => -1], 'car_capacity'],
]);

it('updates a location, audits only real changes, and never changes the code', function () {
    $location = makeLocation();

    $this->actingAs($this->dishub)
        ->put("/locations/{$location->id}", [...locationPayload(['name' => 'Nama Baru']), 'location_code' => 'NEW-CODE'])
        ->assertSessionHasErrors('location_code');

    $this->actingAs($this->dishub)
        ->put("/locations/{$location->id}", collect(locationPayload(['name' => 'Nama Baru', 'latitude' => $location->latitude]))->except('location_code')->all())
        ->assertSessionHasNoErrors();

    $changes = auditOf(AuditAction::LOCATION_CHANGED)->sole()->metadata['changes'];
    expect($changes)->toHaveKey('name')->not->toHaveKey('latitude')
        ->and($location->refresh()->name)->toBe('Nama Baru');
});

it('changes status with a reason', function () {
    $location = makeLocation();

    $this->actingAs($this->dishub)
        ->put("/locations/{$location->id}/status", ['status' => 'SUSPENDED', 'reason' => 'Perbaikan jalan'])
        ->assertSessionHasNoErrors();

    expect($location->refresh()->status)->toBe(LocationStatus::SUSPENDED)
        ->and(auditOf(AuditAction::LOCATION_STATUS_CHANGED)->sole()->metadata)->toBe(['to' => 'SUSPENDED', 'from' => 'ACTIVE', 'reason' => 'Perbaikan jalan']);
});

it('shows locations to viewers but lets only managers change them', function () {
    $location = makeLocation();
    $operator = staffUser(Role::PARKING_OPERATOR);

    $this->actingAs($operator)->get('/locations')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Locations/Index')->has('locations.data', 1));
    $this->actingAs($operator)->get("/locations/{$location->id}")->assertOk()->assertInertia(fn (Assert $p) => $p->component('Locations/Show'));
    $this->actingAs($operator)->post('/locations', locationPayload())->assertForbidden();
    $this->actingAs($operator)->put("/locations/{$location->id}/status", ['status' => 'INACTIVE'])->assertForbidden();
    $this->actingAs(staffUser(Role::FINANCE))->get('/locations/create')->assertForbidden();
});

it('enforces location invariants in the database', function (array $overrides) {
    expect(fn () => DB::transaction(fn () => makeLocation($overrides)))
        ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('23514'));
})->with([
    'lower-case code' => [['location_code' => 'abc-1']],
    'radius' => [['geofence_radius_m' => 0]],
    'longitude' => [['longitude' => '181']],
]);
