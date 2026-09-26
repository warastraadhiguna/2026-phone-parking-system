<?php

use App\Domain\Assignment\Models\Assignment;
use App\Domain\Device\Enums\DeviceStatus;
use App\Domain\Device\Models\Device;
use App\Domain\ParkingLocation\Enums\LocationType;
use App\Domain\Tariff\Enums\TariffStatus;
use App\Domain\Tariff\Models\Tariff;
use App\Support\Time\BusinessTime;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;

beforeEach(function () {
    syncRoles();
    $this->user = attendantUser();
    $this->attendant = attendantOf($this->user);
    $this->location = makeLocation(['location_type' => LocationType::ON_STREET]);
    makeDevice($this->attendant);
    $this->token = test()->postJson('/api/v1/auth/login', [
        'username' => $this->user->username, 'password' => UserFactory::PASSWORD, 'device_uuid' => TEST_DEVICE_UUID,
    ])->json('data.access_token');
});

function bootstrap()
{
    app('auth')->forgetGuards();

    return test()->getJson('/api/v1/bootstrap', ['Authorization' => 'Bearer '.test()->token]);
}

function approvedRow(array $attributes): Tariff
{
    $maker = staffUser();
    $checker = staffUser();

    return Tariff::create([
        'vehicle_type' => 'MOTORCYCLE', 'location_type' => 'ON_STREET', 'amount' => 2000,
        'effective_from' => now()->subDay(), 'regulation_reference' => 'Uji', 'status' => TariffStatus::APPROVED,
        'created_by' => $maker->id, 'approved_by' => $checker->id, 'approved_at' => now()->subDays(2),
        ...$attributes,
    ]);
}

it('gives the app everything it needs to work offline', function () {
    Assignment::create(['attendant_id' => $this->attendant->id, 'location_id' => $this->location->id, 'effective_from' => BusinessTime::today(), 'status' => 'ACTIVE']);
    $current = approvedRow(['effective_until' => now()->addDays(2)]);
    $upcoming = approvedRow(['amount' => 3000, 'effective_from' => now()->addDays(2), 'effective_until' => now()->addDays(30)]);
    approvedRow(['amount' => 9999, 'effective_from' => now()->addDays(30)]); // beyond the cache window
    $specific = approvedRow(['amount' => 1000, 'location_id' => $this->location->id, 'vehicle_type' => 'CAR']);
    approvedRow(['location_type' => 'OFF_STREET', 'amount' => 7000]); // other location type

    $response = bootstrap()->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $data = $response->json('data');

    expect($data['assignment']['location_id'])->toBe($this->location->id)
        ->and($data['location']['location_code'])->toBe($this->location->location_code)
        ->and($data['location']['geofence_radius_m'])->toBe(50)
        ->and(collect($data['tariffs'])->pluck('tariff_id')->sort()->values()->all())->toBe(collect([$current->id, $upcoming->id, $specific->id])->sort()->values()->all())
        ->and(collect($data['tariffs'])->firstWhere('tariff_id', $specific->id)['location_specific'])->toBeTrue()
        ->and($data['settings'])->toMatchArray(['offline_transaction_warning_hours' => 24, 'max_open_shift_hours' => 16, 'offline_config_max_age_hours' => 72])
        ->and($data['open_shift'])->toBeNull()
        ->and($data['business_timezone'])->toBe('Asia/Jakarta');

    // Carbon 3 diffs are signed: generated → valid_until is +72 h.
    expect(CarbonImmutable::parse($data['generated_at'])->diffInHours(CarbonImmutable::parse($data['valid_until'])))->toEqual(72.0);
});

it('returns no location or tariffs without an assignment', function () {
    bootstrap()->assertOk()
        ->assertJsonPath('data.assignment', null)
        ->assertJsonPath('data.location', null)
        ->assertJsonPath('data.tariffs', []);
});

it('is only available to approved devices', function () {
    Device::query()->update(['status' => DeviceStatus::PENDING_APPROVAL->value, 'approved_at' => null]);

    bootstrap()->assertForbidden()->assertJsonPath('error.code', 'DEVICE_NOT_ALLOWED');
});
