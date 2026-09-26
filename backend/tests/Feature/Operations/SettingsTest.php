<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Enums\Role;
use App\Domain\SystemConfiguration\Enums\SettingKey;
use App\Domain\SystemConfiguration\Services\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    syncRoles();
    $this->withoutVite();
    $this->admin = staffUser(Role::SUPER_ADMIN);
});

it('uses code defaults until a value is changed', function () {
    expect(app(Settings::class)->all())->toBe([
        'offline_transaction_warning_hours' => 24,
        'max_open_shift_hours' => 16,
        'offline_config_max_age_hours' => 72,
        'max_clock_skew_minutes' => 10,
        'gps_max_accuracy_m' => 100,
        'qris_expiry_minutes' => 15,
        'qris_status_check_seconds' => 10,
        'movement_max_speed_kmh' => 60,
        'movement_min_distance_m' => 1000,
        'monthly_revenue_target' => 0,
    ]);
});

it('lets system administrators change a setting within bounds, audited', function () {
    $this->actingAs($this->admin)->get('/settings')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Settings/Index')->has('settings', count(SettingKey::cases())));

    $this->actingAs($this->admin)->put('/settings/max_open_shift_hours', ['value' => 12])->assertSessionHasNoErrors();

    expect(app(Settings::class)->int(SettingKey::MAX_OPEN_SHIFT_HOURS))->toBe(12)
        ->and(auditOf(AuditAction::SETTING_CHANGED)->sole()->metadata)->toBe(['to' => 12, 'from' => 16]);

    $this->actingAs($this->admin)->put('/settings/max_open_shift_hours', ['value' => 12]);
    expect(auditOf(AuditAction::SETTING_CHANGED))->toHaveCount(1);
});

it('rejects out-of-range values and unknown keys', function () {
    $this->actingAs($this->admin)->put('/settings/max_open_shift_hours', ['value' => 0])->assertSessionHasErrors('value');
    $this->actingAs($this->admin)->put('/settings/max_open_shift_hours', ['value' => 49])->assertSessionHasErrors('value');
    $this->actingAs($this->admin)->put('/settings/not_a_setting', ['value' => 1])->assertNotFound();
});

it('is restricted to system.configure', function (Role $role) {
    $this->actingAs(staffUser($role))->get('/settings')->assertForbidden();
    $this->actingAs(staffUser($role))->put('/settings/max_open_shift_hours', ['value' => 12])->assertForbidden();
})->with([Role::DISHUB_ADMIN, Role::SUPERVISOR, Role::AUDITOR]);

it('stores only scalar JSON values', function () {
    expect(fn () => DB::transaction(fn () => DB::table('system_settings')->insert(['key' => 'x', 'value' => '{"a":1}', 'updated_at' => now()])))
        ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('23514'));
});
