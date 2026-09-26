<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Enums\Role;
use App\Domain\ParkingLocation\Enums\GeofenceResult;
use App\Domain\Shift\Enums\ShiftFlag;
use App\Domain\Shift\Enums\ShiftStatus;
use App\Domain\Shift\Models\Shift;
use App\Domain\SystemConfiguration\Actions\UpdateSetting;
use App\Domain\SystemConfiguration\Enums\SettingKey;
use App\Domain\SystemConfiguration\Services\Settings;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    syncRoles();
    $this->withoutVite();
    $this->attendant = attendantOf(attendantUser());
    $this->device = makeDevice($this->attendant);
    $this->location = makeLocation();
});

/** An OPEN shift; each call uses a fresh attendant unless one is given (one OPEN shift per attendant). */
function openShift(object $test, int $hoursAgo = 1, ?int $attendantId = null): Shift
{
    return Shift::create([
        'shift_uuid' => (string) Str::uuid(),
        'attendant_id' => $attendantId ?? attendantOf(attendantUser())->id,
        'location_id' => $test->location->id,
        'device_id' => $test->device->id,
        'status' => ShiftStatus::OPEN,
        'started_at_device' => now()->subHours($hoursAgo),
        'started_at_server' => now()->subHours($hoursAgo),
        'start_geofence_result' => GeofenceResult::INSIDE,
        'review_flags' => [],
        'start_payload_hash' => str_repeat('a', 64),
    ]);
}

it('lists shifts for viewers and filters flagged ones', function () {
    openShift($this);
    openShift($this, 30)->forceFill(['status' => 'CLOSED', 'ended_at_server' => now()])->withFlags([ShiftFlag::MOCK_LOCATION])->save();

    $this->actingAs(staffUser(Role::PARKING_OPERATOR))->get('/shifts')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Shifts/Index')->has('shifts.data', 2));
    $this->actingAs(staffUser(Role::PARKING_OPERATOR))->get('/shifts?flagged=1')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->has('shifts.data', 1)->where('shifts.data.0.flags.0.value', 'MOCK_LOCATION'));
    $this->actingAs(staffUser(Role::EXECUTIVE_VIEWER))->get('/shifts')->assertForbidden();
});

it('lets supervisors force-close an open shift with a reason', function () {
    $shift = openShift($this, 20);
    $supervisor = staffUser(Role::SUPERVISOR);

    $this->actingAs($supervisor)->put("/shifts/{$shift->id}/force-close", [])->assertSessionHasErrors('reason');
    $this->actingAs($supervisor)->put("/shifts/{$shift->id}/force-close", ['reason' => 'HP jukir mati'])->assertSessionHasNoErrors();

    $shift->refresh();
    expect($shift->status)->toBe(ShiftStatus::FORCED_CLOSED)
        ->and($shift->force_closed_by)->toBe($supervisor->id)
        ->and($shift->ended_at_device)->toBeNull()
        ->and(auditOf(AuditAction::SHIFT_FORCE_CLOSED)->sole()->metadata['reason'])->toBe('HP jukir mati');

    $this->actingAs($supervisor)->put("/shifts/{$shift->id}/force-close", ['reason' => 'lagi'])->assertSessionHasErrors('reason');
});

it('does not let other roles force-close', function (Role $role) {
    $shift = openShift($this);

    $this->actingAs(staffUser($role))->put("/shifts/{$shift->id}/force-close", ['reason' => 'x'])->assertForbidden();
    expect($shift->refresh()->status)->toBe(ShiftStatus::OPEN);
})->with([Role::PARKING_OPERATOR, Role::DISHUB_ADMIN, Role::SUPER_ADMIN, Role::FINANCE]);

it('flags overdue shifts once, using the configurable threshold', function () {
    $overdue = openShift($this, 17);
    $recent = openShift($this, 2);

    $this->artisan('shifts:flag-overdue')->expectsOutputToContain('1 shift(s)')->assertSuccessful();
    $this->artisan('shifts:flag-overdue')->expectsOutputToContain('0 shift(s)');

    expect($overdue->refresh()->review_flags)->toBe(['OVERDUE'])
        ->and($recent->refresh()->review_flags)->toBe([])
        ->and(auditOf(AuditAction::SHIFT_FLAGGED)->sole()->actor_type->value)->toBe('SYSTEM');

    app(UpdateSetting::class)->handle(SettingKey::MAX_OPEN_SHIFT_HOURS, 1, staffUser(Role::SUPER_ADMIN));
    app(Settings::class)->forget();
    $this->artisan('shifts:flag-overdue')->expectsOutputToContain('1 shift(s)');
    expect($recent->refresh()->review_flags)->toBe(['OVERDUE']);
});
