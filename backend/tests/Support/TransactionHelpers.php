<?php

use App\Domain\Assignment\Models\Assignment;
use App\Domain\ParkingLocation\Enums\LocationType;
use App\Domain\Tariff\Enums\TariffStatus;
use App\Domain\Tariff\Models\Tariff;
use App\Support\Time\BusinessTime;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
| Shared arrangement for transaction tests: an attendant with an ACTIVE device, an assignment,
| a motorcycle tariff of Rp2.000 and an open shift, all created through the real API.
*/

const TX_SHIFT_UUID = '6b2c3d4e-5f60-4a7b-8c9d-0e1f2a3b4c5d';

function arrangeCashTest(object $test): void
{
    syncRoles();
    $test->user = attendantUser();
    $test->attendant = attendantOf($test->user);
    $test->location = makeLocation(['location_type' => LocationType::ON_STREET]);
    Assignment::create([
        'attendant_id' => $test->attendant->id, 'location_id' => $test->location->id,
        'effective_from' => CarbonImmutable::now(BusinessTime::timezone())->subDays(3)->toDateString(), 'status' => 'ACTIVE',
    ]);
    $test->device = makeDevice($test->attendant);
    $test->device->forceFill(['approved_at' => now()->subDays(10)])->save();
    $test->tariff = tariffRow(2000);

    $test->token = test()->postJson('/api/v1/auth/login', [
        'username' => $test->user->username, 'password' => UserFactory::PASSWORD, 'device_uuid' => TEST_DEVICE_UUID,
    ])->json('data.access_token');

    txApi('POST', '/api/v1/shifts/start', [
        'shift_uuid' => TX_SHIFT_UUID,
        'location_id' => $test->location->id,
        'started_at_device' => now()->subHour()->toIso8601String(),
        'latitude' => -6.7551, 'longitude' => 111.0380, 'gps_accuracy_m' => 8,
    ])->assertCreated();
}

/** An approved tariff row inserted directly (approval rules are tested elsewhere). */
function tariffRow(int $amount, array $overrides = []): Tariff
{
    $maker = staffUser();
    $checker = staffUser();

    return Tariff::create([
        'vehicle_type' => 'MOTORCYCLE', 'location_type' => 'ON_STREET', 'amount' => $amount,
        'effective_from' => now()->subDays(5), 'regulation_reference' => 'Uji', 'status' => TariffStatus::APPROVED,
        'created_by' => $maker->id, 'approved_by' => $checker->id, 'approved_at' => now()->subDays(6),
        ...$overrides,
    ]);
}

function txApi(string $method, string $uri, array $body = []): TestResponse
{
    app('auth')->forgetGuards();

    return test()->json($method, $uri, $body, ['Authorization' => 'Bearer '.test()->token]);
}

/** @return array<string, mixed> */
function cashPayload(array $overrides = []): array
{
    static $seq = 0;

    return [
        'transaction_uuid' => (string) Str::uuid(),
        'shift_uuid' => TX_SHIFT_UUID,
        'sync_sequence' => ++$seq,
        'vehicle_type' => 'MOTORCYCLE',
        'payment_method' => 'CASH',
        'charged_amount' => 2000,
        'tariff_id' => test()->tariff->id,
        'transaction_time_device' => now()->toIso8601String(),
        'latitude' => -6.7551, 'longitude' => 111.0380, 'gps_accuracy_m' => 8,
        ...$overrides,
    ];
}
