<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Device\Enums\DeviceStatus;
use App\Domain\Device\Models\Device;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\MobileRefreshToken;
use App\Domain\Identity\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    syncRoles();
    $this->withoutVite();
    $this->dishub = staffUser(Role::DISHUB_ADMIN);
    $this->user = attendantUser();
    $this->attendant = attendantOf($this->user);

    // An operational endpoint, as shifts and transactions will be (Phase 3+).
    Route::middleware(['api', 'auth:sanctum', 'mobile.attendant', 'mobile.device'])
        ->get('api/v1/_test/operational', fn () => response()->json(['ok' => true]));
});

function deviceLogin(string $username, string $device = TEST_DEVICE_UUID, array $extra = []): TestResponse
{
    app('auth')->forgetGuards();

    return test()->postJson('/api/v1/auth/login', ['username' => $username, 'password' => UserFactory::PASSWORD, 'device_uuid' => $device, ...$extra]);
}

function operational(string $accessToken): TestResponse
{
    app('auth')->forgetGuards();

    return test()->getJson('/api/v1/_test/operational', ['Authorization' => 'Bearer '.$accessToken]);
}

it('registers an unknown device as pending at first login', function () {
    $response = deviceLogin($this->user->username, extra: ['device_model' => 'Samsung A15', 'android_version' => '14', 'app_version' => '1.0.0'])
        ->assertOk()
        ->assertJsonPath('data.device.status', 'PENDING_APPROVAL');

    $device = Device::sole();
    expect($device->attendant_id)->toBe($this->attendant->id)
        ->and($device->device_model)->toBe('Samsung A15')
        ->and($device->status)->toBe(DeviceStatus::PENDING_APPROVAL);
    expect(auditOf(AuditAction::DEVICE_REGISTERED)->sole()->device_uuid)->toBe(TEST_DEVICE_UUID);

    // Logged in, can see its status, but cannot operate.
    $token = $response->json('data.access_token');
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])->assertOk()->assertJsonPath('data.device.status', 'PENDING_APPROVAL');
    operational($token)->assertForbidden()->assertJsonPath('error.code', 'DEVICE_NOT_ALLOWED')->assertJsonPath('error.message', 'Perangkat menunggu persetujuan admin.');
});

it('opens operational endpoints once an admin approves the device', function () {
    $token = deviceLogin($this->user->username)->json('data.access_token');

    $this->actingAs($this->dishub)->put('/devices/'.Device::sole()->id.'/approve')->assertSessionHasNoErrors();

    expect(Device::sole()->status)->toBe(DeviceStatus::ACTIVE)
        ->and(Device::sole()->approved_by)->toBe($this->dishub->id)
        ->and(auditOf(AuditAction::DEVICE_APPROVED))->toHaveCount(1);
    operational($token)->assertOk();
});

it('allows only one active device per attendant', function () {
    makeDevice($this->attendant, OTHER_DEVICE_UUID, DeviceStatus::ACTIVE);
    deviceLogin($this->user->username);
    $pending = Device::query()->where('device_uuid', TEST_DEVICE_UUID)->sole();

    $this->actingAs($this->dishub)
        ->put("/devices/{$pending->id}/approve")
        ->assertSessionHasErrors(['device' => 'Juru parkir ini sudah memiliki perangkat aktif. Cabut perangkat lama terlebih dahulu.']);

    // The database refuses it as well.
    expect(fn () => DB::transaction(fn () => $pending->forceFill(['status' => 'ACTIVE', 'approved_at' => now()])->save()))
        ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('23505'));
});

it('revokes a device: sessions end, login and refresh are refused', function () {
    makeDevice($this->attendant);
    $tokens = deviceLogin($this->user->username)->assertJsonPath('data.device.status', 'ACTIVE')->json('data');
    $device = Device::sole();

    $this->actingAs($this->dishub)
        ->put("/devices/{$device->id}/deactivate", ['status' => 'REVOKED', 'reason' => 'Ganti HP'])
        ->assertSessionHasNoErrors();

    expect($device->refresh()->status)->toBe(DeviceStatus::REVOKED)
        ->and($device->deactivation_reason)->toBe('Ganti HP')
        ->and(MobileRefreshToken::query()->whereNull('revoked_at')->count())->toBe(0);
    operational($tokens['access_token'])->assertUnauthorized();

    deviceLogin($this->user->username)->assertForbidden()->assertJsonPath('error.code', 'DEVICE_NOT_ALLOWED');
    expect(auditOf(AuditAction::LOGIN_FAILED)->last()->metadata['reason'])->toBe('device_not_allowed');

    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token'], 'device_uuid' => TEST_DEVICE_UUID])->assertUnauthorized();
});

it('refuses refresh as soon as the device is revoked, even if tokens were not revoked', function () {
    makeDevice($this->attendant);
    $tokens = deviceLogin($this->user->username)->json('data');
    Device::query()->update(['status' => 'LOST', 'deactivated_at' => now()]);

    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token'], 'device_uuid' => TEST_DEVICE_UUID])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'DEVICE_NOT_ALLOWED');
    expect(MobileRefreshToken::query()->whereNull('revoked_at')->count())->toBe(0);
});

it('refuses a device registered to another attendant', function () {
    $other = attendantOf(attendantUser());
    makeDevice($other, TEST_DEVICE_UUID, DeviceStatus::ACTIVE);

    deviceLogin($this->user->username)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'DEVICE_NOT_ALLOWED')
        ->assertJsonPath('error.message', 'Perangkat ini terdaftar untuk juru parkir lain.');
});

it('treats revoked and lost as final', function (DeviceStatus $final) {
    $device = makeDevice($this->attendant, status: $final);

    $this->actingAs($this->dishub)->put("/devices/{$device->id}/approve")->assertSessionHasErrors('device');
    $this->actingAs($this->dishub)->put("/devices/{$device->id}/deactivate", ['status' => 'REVOKED', 'reason' => 'x'])->assertSessionHasErrors('device');
})->with([DeviceStatus::REVOKED, DeviceStatus::LOST]);

it('lets a pending device be rejected', function () {
    deviceLogin($this->user->username);
    $device = Device::sole();

    $this->actingAs($this->dishub)->put("/devices/{$device->id}/deactivate", ['status' => 'REVOKED', 'reason' => 'Bukan HP resmi'])->assertSessionHasNoErrors();

    expect($device->refresh()->status)->toBe(DeviceStatus::REVOKED);
});

it('requires devices.manage to approve or revoke, and devices.view to list', function () {
    $device = makeDevice($this->attendant, status: DeviceStatus::PENDING_APPROVAL);

    $this->actingAs(staffUser(Role::PARKING_OPERATOR))->get('/devices')->assertOk();
    $this->actingAs(staffUser(Role::PARKING_OPERATOR))->put("/devices/{$device->id}/approve")->assertForbidden();
    $this->actingAs(staffUser(Role::FINANCE))->get('/devices')->assertForbidden();
    expect($device->refresh()->status)->toBe(DeviceStatus::PENDING_APPROVAL);
});

it('does not admit attendant accounts without a registry record', function () {
    $orphan = User::factory()->attendant()->create();

    deviceLogin($orphan->username)->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_DISABLED');
    expect(Device::count())->toBe(0);
});
