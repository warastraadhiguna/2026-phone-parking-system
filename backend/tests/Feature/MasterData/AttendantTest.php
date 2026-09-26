<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Enums\AccountType;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\MobileRefreshToken;
use App\Domain\Identity\Models\User;
use App\Domain\ParkingAttendant\Enums\AttendantStatus;
use App\Domain\ParkingAttendant\Models\ParkingAttendant;
use App\Support\Time\BusinessTime;
use Database\Factories\UserFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    syncRoles();
    $this->withoutVite();
    $this->dishub = staffUser(Role::DISHUB_ADMIN);
});

/** @return array<string, mixed> */
function attendantPayload(array $overrides = []): array
{
    return [
        'name' => 'Slamet Riyadi',
        'identity_number' => '3318 0101 0101 0001',
        'phone' => '0812-3456-7890',
        'registered_at' => BusinessTime::today(),
        'expired_at' => '',
        'password' => 'Jukir-Awal-2026',
        'password_confirmation' => 'Jukir-Awal-2026',
        ...$overrides,
    ];
}

it('registers an attendant together with a mobile login account', function () {
    $this->actingAs($this->dishub)->post('/attendants', attendantPayload())->assertRedirect()->assertSessionHas('success');

    $attendant = ParkingAttendant::sole();
    $user = $attendant->user;

    expect($attendant->attendant_code)->toMatch('/^JP-\d{6}$/')
        ->and($attendant->identity_number)->toBe('3318010101010001')
        ->and($attendant->phone)->toBe('081234567890')
        ->and($user->username)->toBe(strtolower($attendant->attendant_code))
        ->and($user->account_type)->toBe(AccountType::ATTENDANT)
        ->and($user->hasRole('PARKING_ATTENDANT'))->toBeTrue()
        ->and(Hash::check('Jukir-Awal-2026', $user->password))->toBeTrue();

    $audit = auditOf(AuditAction::ATTENDANT_REGISTERED)->sole();
    expect($audit->actor_id)->toBe($this->dishub->id)
        ->and(json_encode($audit->metadata))->not->toContain('3318010101010001');
});

it('generates sequential attendant codes', function () {
    $this->actingAs($this->dishub)->post('/attendants', attendantPayload());
    $this->actingAs($this->dishub)->post('/attendants', attendantPayload(['identity_number' => '3318010101010002']));

    [$a, $b] = ParkingAttendant::query()->orderBy('id')->pluck('attendant_code')->all();
    expect((int) substr($b, 3))->toBe((int) substr($a, 3) + 1);
});

it('validates attendant input', function (array $overrides, string $field) {
    $this->actingAs($this->dishub)->post('/attendants', attendantPayload());

    $this->actingAs($this->dishub)->post('/attendants', attendantPayload($overrides))->assertSessionHasErrors($field);
})->with([
    'duplicate NIK' => [[], 'identity_number'],
    'short NIK' => [['identity_number' => '12345'], 'identity_number'],
    'bad phone' => [['identity_number' => '3318010101010009', 'phone' => '12'], 'phone'],
    'expiry before registration' => [['identity_number' => '3318010101010009', 'expired_at' => '2000-01-01'], 'expired_at'],
    'weak password' => [['identity_number' => '3318010101010009', 'password' => 'abc', 'password_confirmation' => 'abc'], 'password'],
]);

it('keeps the login name in sync and records a NIK change without its value', function () {
    $this->actingAs($this->dishub)->post('/attendants', attendantPayload());
    $attendant = ParkingAttendant::sole();

    $this->actingAs($this->dishub)
        ->put("/attendants/{$attendant->id}", collect(attendantPayload(['name' => 'Slamet R.', 'identity_number' => '3318010101010077']))->except(['password', 'password_confirmation'])->all())
        ->assertSessionHasNoErrors();

    expect($attendant->refresh()->user->name)->toBe('Slamet R.');
    $changes = auditOf(AuditAction::ATTENDANT_CHANGED)->sole()->metadata['changes'];
    expect(json_encode($changes))->not->toContain('3318010101010077')->not->toContain('3318010101010001');
});

it('mirrors attendant status on the login account and ends mobile sessions', function () {
    $user = attendantUser();
    $attendant = attendantOf($user);
    $this->postJson('/api/v1/auth/login', ['username' => $user->username, 'password' => UserFactory::PASSWORD, 'device_uuid' => TEST_DEVICE_UUID])->assertOk();

    $this->actingAs($this->dishub)
        ->put("/attendants/{$attendant->id}/status", ['status' => 'SUSPENDED', 'reason' => 'Setoran belum lengkap'])
        ->assertSessionHasNoErrors();

    expect($attendant->refresh()->status)->toBe(AttendantStatus::SUSPENDED)
        ->and($user->refresh()->status)->toBe(UserStatus::SUSPENDED)
        ->and(MobileRefreshToken::query()->whereNull('revoked_at')->count())->toBe(0)
        ->and(auditOf(AuditAction::ATTENDANT_STATUS_CHANGED)->sole()->metadata['reason'])->toBe('Setoran belum lengkap');

    $this->actingAs($this->dishub)->put("/attendants/{$attendant->id}/status", ['status' => 'ACTIVE', 'reason' => 'Lunas']);
    expect($user->refresh()->status)->toBe(UserStatus::ACTIVE);
});

it('requires a reason for status changes', function () {
    $attendant = attendantOf(attendantUser());

    $this->actingAs($this->dishub)->put("/attendants/{$attendant->id}/status", ['status' => 'INACTIVE'])->assertSessionHasErrors('reason');
});

it('expires attendants whose validity ended, once', function () {
    $expired = attendantOf(attendantUser());
    $expired->forceFill(['registered_at' => '2025-01-01', 'expired_at' => now(BusinessTime::timezone())->subDay()->toDateString()])->save();
    $validToday = attendantOf(attendantUser());
    $validToday->forceFill(['expired_at' => BusinessTime::today()])->save();

    $this->artisan('attendants:expire')->expectsOutputToContain('1 attendant(s) expired.')->assertSuccessful();
    $this->artisan('attendants:expire')->expectsOutputToContain('0 attendant(s) expired.');

    expect($expired->refresh()->status)->toBe(AttendantStatus::EXPIRED)
        ->and($expired->user->status)->toBe(UserStatus::INACTIVE)
        ->and($validToday->refresh()->status)->toBe(AttendantStatus::ACTIVE);

    $audit = auditOf(AuditAction::ATTENDANT_STATUS_CHANGED)->sole();
    expect($audit->actor_type->value)->toBe('SYSTEM');
});

it('stores photos privately and serves them only to authorised staff', function () {
    Storage::fake('local');
    $attendant = attendantOf(attendantUser());

    $this->actingAs($this->dishub)
        ->post("/attendants/{$attendant->id}/photo", ['photo' => UploadedFile::fake()->image('foto.jpg', 300, 300)])
        ->assertSessionHasNoErrors();

    $path = $attendant->refresh()->photo_path;
    Storage::disk('local')->assertExists($path);

    $this->actingAs(staffUser(Role::PARKING_OPERATOR))->get("/attendants/{$attendant->id}/photo")->assertOk();
    $this->actingAs(staffUser(Role::EXECUTIVE_VIEWER))->get("/attendants/{$attendant->id}/photo")->assertForbidden();

    $this->actingAs($this->dishub)
        ->post("/attendants/{$attendant->id}/photo", ['photo' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])
        ->assertSessionHasErrors('photo');
});

it('masks the NIK for viewers who cannot manage attendants', function () {
    $user = attendantUser();
    $attendant = attendantOf($user);

    $this->actingAs(staffUser(Role::PARKING_OPERATOR))
        ->get("/attendants/{$attendant->id}")
        ->assertInertia(fn (Assert $p) => $p->component('Attendants/Show')
            ->where('attendant.identity_number', fn (string $nik) => str_starts_with($nik, '••••') && str_ends_with($nik, substr($attendant->identity_number, -4))));

    $this->actingAs($this->dishub)
        ->get("/attendants/{$attendant->id}")
        ->assertInertia(fn (Assert $p) => $p->where('attendant.identity_number', $attendant->identity_number));

    $this->actingAs(staffUser(Role::PARKING_OPERATOR))
        ->get('/attendants')
        ->assertInertia(fn (Assert $p) => $p->missing('attendants.data.0.identity_number')->has('attendants.data.0.identity_number_masked'));
});

it('lets only attendant managers register or change attendants', function () {
    $attendant = attendantOf(attendantUser());
    $operator = staffUser(Role::PARKING_OPERATOR);

    $this->actingAs($operator)->post('/attendants', attendantPayload())->assertForbidden();
    $this->actingAs($operator)->put("/attendants/{$attendant->id}/status", ['status' => 'INACTIVE', 'reason' => 'x'])->assertForbidden();
    expect(User::query()->where('account_type', 'ATTENDANT')->count())->toBe(1);
});
