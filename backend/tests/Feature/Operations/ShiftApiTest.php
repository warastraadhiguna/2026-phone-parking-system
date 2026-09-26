<?php

use App\Domain\Assignment\Models\Assignment;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Device\Enums\DeviceStatus;
use App\Domain\Device\Models\Device;
use App\Domain\ParkingLocation\Enums\LocationStatus;
use App\Domain\Shift\Enums\ShiftStatus;
use App\Domain\Shift\Models\Shift;
use App\Support\Time\BusinessTime;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    syncRoles();
    $this->user = attendantUser();
    $this->attendant = attendantOf($this->user);
    $this->location = makeLocation(['latitude' => '-6.7550000', 'longitude' => '111.0380000', 'geofence_radius_m' => 50]);
    $this->assignment = Assignment::create([
        'attendant_id' => $this->attendant->id, 'location_id' => $this->location->id,
        'effective_from' => CarbonImmutable::now(BusinessTime::timezone())->subDays(3)->toDateString(), 'status' => 'ACTIVE',
    ]);
    $this->device = makeDevice($this->attendant);
    $this->device->forceFill(['approved_at' => now()->subDays(10)])->save();
    $this->token = test()->postJson('/api/v1/auth/login', [
        'username' => $this->user->username, 'password' => UserFactory::PASSWORD, 'device_uuid' => TEST_DEVICE_UUID,
    ])->json('data.access_token');
});

function api(string $method, string $uri, array $body = []): TestResponse
{
    app('auth')->forgetGuards();

    return test()->json($method, $uri, $body, ['Authorization' => 'Bearer '.test()->token]);
}

/** @return array<string, mixed> */
function startPayload(array $overrides = []): array
{
    return [
        'shift_uuid' => '5a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d',
        'location_id' => test()->location->id,
        'started_at_device' => now()->toIso8601String(),
        'latitude' => -6.7551,       // ~11 m from the location point
        'longitude' => 111.0380,
        'gps_accuracy_m' => 8,
        'mock_location' => false,
        'offline_created' => false,
        ...$overrides,
    ];
}

/** @return array<string, mixed> */
function endPayload(array $overrides = []): array
{
    return [
        'shift_uuid' => '5a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d',
        'ended_at_device' => now()->addHours(8)->toIso8601String(),
        'latitude' => -6.7550,
        'longitude' => 111.0381,
        'gps_accuracy_m' => 10,
        ...$overrides,
    ];
}

describe('start', function () {
    it('starts a shift at the assigned location with GPS and audit', function () {
        api('POST', '/api/v1/shifts/start', startPayload())
            ->assertCreated()
            ->assertJsonPath('data.replayed', false)
            ->assertJsonPath('data.shift.status', 'OPEN')
            ->assertJsonPath('data.shift.start_geofence_result', 'INSIDE')
            ->assertJsonPath('data.shift.assignment_id', $this->assignment->id)
            ->assertJsonPath('data.shift.review_flags', []);

        $shift = Shift::sole();
        expect($shift->device_id)->toBe($this->device->id)
            ->and($shift->start_distance_m)->toBeBetween(5, 20)
            ->and($shift->offline_created)->toBeFalse();

        $audit = auditOf(AuditAction::START_SHIFT)->sole();
        expect($audit->entity_id)->toBe($shift->shift_uuid)->and($audit->device_uuid)->toBe(TEST_DEVICE_UUID);
    });

    it('is idempotent: a retry returns the same shift', function () {
        $payload = startPayload();
        api('POST', '/api/v1/shifts/start', $payload)->assertCreated();
        api('POST', '/api/v1/shifts/start', $payload)->assertOk()->assertJsonPath('data.replayed', true);

        expect(Shift::count())->toBe(1)->and(auditOf(AuditAction::START_SHIFT))->toHaveCount(1);
    });

    it('reports a conflict when the same UUID arrives with different data', function () {
        api('POST', '/api/v1/shifts/start', startPayload())->assertCreated();

        api('POST', '/api/v1/shifts/start', startPayload(['latitude' => -6.9]))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SYNC_CONFLICT');
    });

    it('allows only one open shift per attendant (app and database)', function () {
        api('POST', '/api/v1/shifts/start', startPayload())->assertCreated();

        api('POST', '/api/v1/shifts/start', startPayload(['shift_uuid' => (string) Str::uuid()]))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SHIFT_ALREADY_OPEN')
            ->assertJsonPath('error.details.open_shift_uuid', '5a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d');

        $open = Shift::sole();
        expect(fn () => DB::transaction(fn () => Shift::create([
            ...collect($open->getAttributes())->except(['id', 'shift_uuid', 'review_flags'])->all(),
            'shift_uuid' => (string) Str::uuid(), 'review_flags' => [],
        ])))->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('23505'));
    });

    it('validates online starts strictly', function (Closure $arrange, string $message) {
        $arrange($this);

        api('POST', '/api/v1/shifts/start', startPayload(['location_id' => $this->location->id]))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'LOCATION_NOT_ALLOWED')
            ->assertJsonPath('error.message', $message);
        expect(Shift::count())->toBe(0);
    })->with([
        'no assignment today' => [fn ($t) => $t->assignment->forceFill(['status' => 'CANCELLED'])->save(), 'Tidak ada penugasan untuk hari ini.'],
        'other location' => [fn ($t) => $t->location = makeLocation(), 'Lokasi ini bukan lokasi penugasan Anda hari ini.'],
        'inactive location' => [fn ($t) => $t->location->forceFill(['status' => LocationStatus::INACTIVE])->save(), 'Lokasi tidak aktif.'],
    ]);

    it('accepts an offline shift that already happened and flags rule violations', function () {
        $this->assignment->forceFill(['status' => 'CANCELLED'])->save();

        api('POST', '/api/v1/shifts/start', startPayload(['offline_created' => true, 'started_at_device' => now()->subHours(2)->toIso8601String()]))
            ->assertCreated()
            ->assertJsonPath('data.shift.offline_created', true)
            ->assertJsonPath('data.shift.review_flags', ['NO_ASSIGNMENT']);
    });

    it('checks the assignment of the day an offline shift really started', function () {
        $this->assignment->forceFill(['effective_from' => BusinessTime::today()])->save();

        api('POST', '/api/v1/shifts/start', startPayload(['offline_created' => true, 'started_at_device' => now()->subDays(2)->toIso8601String()]))
            ->assertCreated()
            ->assertJsonPath('data.shift.review_flags', ['NO_ASSIGNMENT', 'STALE_OFFLINE']);
    });

    it('flags timing, position and device-approval signals', function (array $overrides, array $flags, string $geofence) {
        api('POST', '/api/v1/shifts/start', startPayload($overrides))
            ->assertCreated()
            ->assertJsonPath('data.shift.review_flags', $flags)
            ->assertJsonPath('data.shift.start_geofence_result', $geofence);
    })->with([
        'online clock skew' => [['started_at_device' => now()->addMinutes(30)->toIso8601String()], ['CLOCK_SKEW'], 'INSIDE'],
        'offline in the future' => [['offline_created' => true, 'started_at_device' => now()->addHour()->toIso8601String()], ['CLOCK_SKEW'], 'INSIDE'],
        'far outside geofence' => [['latitude' => -6.7650, 'gps_accuracy_m' => 10], ['OUTSIDE_GEOFENCE_START'], 'OUTSIDE'],
        'mock location' => [['mock_location' => true], ['MOCK_LOCATION'], 'INSIDE'],
        'poor accuracy' => [['latitude' => -6.7650, 'gps_accuracy_m' => 500], [], 'UNKNOWN'],
        'no GPS' => [['latitude' => null, 'longitude' => null, 'gps_accuracy_m' => null], [], 'UNKNOWN'],
        'just outside, within accuracy' => [['latitude' => -6.7555, 'gps_accuracy_m' => 30], [], 'UNKNOWN'],
    ]);

    it('flags an offline shift that started before the device was approved', function () {
        $this->device->forceFill(['approved_at' => now()->subHour()])->save();

        api('POST', '/api/v1/shifts/start', startPayload(['offline_created' => true, 'started_at_device' => now()->subHours(3)->toIso8601String()]))
            ->assertCreated()
            ->assertJsonPath('data.shift.review_flags', ['DEVICE_NOT_APPROVED_AT_START']);
    });

    it('requires an approved device', function () {
        Device::query()->update(['status' => DeviceStatus::PENDING_APPROVAL->value, 'approved_at' => null]);

        api('POST', '/api/v1/shifts/start', startPayload())->assertForbidden()->assertJsonPath('error.code', 'DEVICE_NOT_ALLOWED');
    });

    it('requires device times with an explicit offset', function () {
        api('POST', '/api/v1/shifts/start', startPayload(['started_at_device' => '2026-09-25 08:00:00']))
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['started_at_device']]]]);
    });
});

describe('end', function () {
    beforeEach(fn () => api('POST', '/api/v1/shifts/start', startPayload())->assertCreated());

    it('ends the shift and records the end position', function () {
        api('POST', '/api/v1/shifts/end', endPayload())
            ->assertOk()
            ->assertJsonPath('data.shift.status', 'CLOSED')
            ->assertJsonPath('data.shift.end_geofence_result', 'INSIDE')
            ->assertJsonPath('data.replayed', false);

        expect(Shift::sole()->status)->toBe(ShiftStatus::CLOSED)
            ->and(auditOf(AuditAction::END_SHIFT)->sole()->metadata['duration_minutes'])->toBeGreaterThanOrEqual(479);
    });

    it('is idempotent and detects conflicting ends', function () {
        $payload = endPayload();
        api('POST', '/api/v1/shifts/end', $payload)->assertOk();
        api('POST', '/api/v1/shifts/end', $payload)->assertOk()->assertJsonPath('data.replayed', true);
        api('POST', '/api/v1/shifts/end', endPayload(['ended_at_device' => now()->addHours(9)->toIso8601String()]))
            ->assertStatus(409)->assertJsonPath('error.code', 'SYNC_CONFLICT');

        expect(auditOf(AuditAction::END_SHIFT))->toHaveCount(1);
    });

    it('refuses an end before the start', function () {
        api('POST', '/api/v1/shifts/end', endPayload(['ended_at_device' => now()->subHour()->toIso8601String()]))
            ->assertUnprocessable()->assertJsonPath('error.details.field', 'ended_at_device');
    });

    it('does not reveal or end shifts of others', function () {
        api('POST', '/api/v1/shifts/end', endPayload(['shift_uuid' => (string) Str::uuid()]))->assertNotFound();

        Shift::query()->update(['attendant_id' => attendantOf(attendantUser())->id]);
        api('POST', '/api/v1/shifts/end', endPayload())->assertNotFound();
    });

    it('reports a force-closed shift as such instead of failing', function () {
        Shift::query()->update(['status' => 'FORCED_CLOSED', 'ended_at_server' => now(), 'force_closed_by' => staffUser()->id, 'close_reason' => 'Overdue']);

        api('POST', '/api/v1/shifts/end', endPayload())->assertOk()
            ->assertJsonPath('data.shift.status', 'FORCED_CLOSED')
            ->assertJsonPath('data.replayed', true);
    });

    it('lets the app read its active shift and a shift by UUID', function () {
        api('GET', '/api/v1/shifts/active')->assertOk()->assertJsonPath('data.shift.shift_uuid', '5a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d');
        api('GET', '/api/v1/shifts/5a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d')->assertOk()->assertJsonPath('data.shift.status', 'OPEN');

        api('POST', '/api/v1/shifts/end', endPayload());
        api('GET', '/api/v1/shifts/active')->assertOk()->assertJsonPath('data.shift', null);
    });
});
