<?php

use App\Domain\Assignment\Enums\AssignmentStatus;
use App\Domain\Assignment\Models\Assignment;
use App\Domain\Assignment\Services\AssignmentLookup;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Device\Enums\DeviceStatus;
use App\Domain\Identity\Enums\Role;
use App\Domain\ParkingAttendant\Enums\AttendantStatus;
use App\Domain\ParkingLocation\Enums\LocationStatus;
use App\Support\Time\BusinessTime;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    syncRoles();
    $this->dishub = staffUser(Role::DISHUB_ADMIN);
    $this->user = attendantUser();
    $this->attendant = attendantOf($this->user);
    $this->location = makeLocation();
    $this->today = BusinessTime::today();
});

function day(int $offset): string
{
    return CarbonImmutable::now(BusinessTime::timezone())->addDays($offset)->toDateString();
}

function assign(object $test, ?int $locationId = null, ?string $from = null, ?string $until = null)
{
    return $test->actingAs($test->dishub)->post("/attendants/{$test->attendant->id}/assignments", [
        'location_id' => $locationId ?? $test->location->id,
        'effective_from' => $from ?? $test->today,
        'effective_until' => $until,
    ]);
}

it('assigns an attendant to a location from today', function () {
    assign($this)->assertSessionHasNoErrors();

    $assignment = Assignment::sole();
    expect($assignment->status)->toBe(AssignmentStatus::ACTIVE)
        ->and($assignment->effective_until)->toBeNull()
        ->and(app(AssignmentLookup::class)->currentFor($this->attendant)?->id)->toBe($assignment->id)
        ->and(auditOf(AuditAction::ASSIGNMENT_CREATED)->sole()->metadata['location_id'])->toBe($this->location->id);
});

it('refuses overlapping assignments for the same attendant (also in the database)', function () {
    assign($this, from: $this->today, until: day(10))->assertSessionHasNoErrors();
    $other = makeLocation();

    assign($this, $other->id, day(5), day(20))
        ->assertSessionHasErrors(['effective_from' => 'Periode ini bertumpang tindih dengan penugasan lain juru parkir tersebut.']);
    assign($this, $other->id, day(11))->assertSessionHasNoErrors();

    expect(fn () => DB::transaction(fn () => Assignment::create([
        'attendant_id' => $this->attendant->id, 'location_id' => $other->id, 'effective_from' => day(3), 'status' => 'ACTIVE',
    ])))->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('23P01'));
});

it('allows several attendants at one location', function () {
    assign($this)->assertSessionHasNoErrors();
    $this->attendant = attendantOf(attendantUser());
    assign($this)->assertSessionHasNoErrors();

    expect(Assignment::query()->where('location_id', $this->location->id)->count())->toBe(2);
});

it('refuses backdating and inactive parties', function () {
    assign($this, from: day(-1))->assertSessionHasErrors('effective_from');

    $this->location->forceFill(['status' => LocationStatus::INACTIVE])->save();
    assign($this)->assertSessionHasErrors('location_id');

    $this->location->forceFill(['status' => LocationStatus::ACTIVE])->save();
    $this->attendant->forceFill(['status' => AttendantStatus::SUSPENDED])->save();
    assign($this)->assertSessionHasErrors('attendant_id');

    expect(Assignment::count())->toBe(0);
});

it('ends a running assignment but never in the past', function () {
    assign($this);
    $assignment = Assignment::sole();

    $this->actingAs($this->dishub)->put("/assignments/{$assignment->id}/end", ['effective_until' => day(-1)])->assertSessionHasErrors('effective_until');
    $this->actingAs($this->dishub)->put("/assignments/{$assignment->id}/end", ['effective_until' => $this->today])->assertSessionHasNoErrors();

    expect($assignment->refresh()->effective_until->toDateString())->toBe($this->today)
        ->and(auditOf(AuditAction::ASSIGNMENT_ENDED)->sole()->metadata)->toBe(['to' => $this->today, 'from' => null]);
});

it('cancels only assignments that have not started', function () {
    assign($this, from: $this->today);
    $running = Assignment::sole();
    $this->actingAs($this->dishub)->put("/assignments/{$running->id}/cancel", ['reason' => 'Salah input'])->assertSessionHasErrors('reason');

    $this->actingAs($this->dishub)->put("/assignments/{$running->id}/end", ['effective_until' => $this->today]);
    assign($this, from: day(3));
    $scheduled = Assignment::query()->latest('id')->first();
    $this->actingAs($this->dishub)->put("/assignments/{$scheduled->id}/cancel", ['reason' => 'Salah input'])->assertSessionHasNoErrors();

    expect($scheduled->refresh()->status)->toBe(AssignmentStatus::CANCELLED)
        ->and($scheduled->cancel_reason)->toBe('Salah input');
    // A cancelled period no longer blocks a new one.
    assign($this, from: day(3))->assertSessionHasNoErrors();
});

it('tells the app today\'s assignment', function () {
    assign($this);
    makeDevice($this->attendant, TEST_DEVICE_UUID, DeviceStatus::ACTIVE);
    $token = $this->postJson('/api/v1/auth/login', ['username' => $this->user->username, 'password' => UserFactory::PASSWORD, 'device_uuid' => TEST_DEVICE_UUID])->json('data.access_token');

    app('auth')->forgetGuards();
    $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])
        ->assertOk()
        ->assertJsonPath('data.assignment.location_code', $this->location->location_code)
        ->assertJsonPath('data.attendant.attendant_code', $this->attendant->attendant_code)
        ->assertJsonPath('data.device.status', 'ACTIVE');
});

it('requires assignments.manage', function () {
    $this->actingAs(staffUser(Role::PARKING_OPERATOR))
        ->post("/attendants/{$this->attendant->id}/assignments", ['location_id' => $this->location->id, 'effective_from' => $this->today])
        ->assertForbidden();
});
