<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Enums\AccountType;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\MobileRefreshToken;
use App\Domain\Identity\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    syncRoles();
    $this->withoutVite();
    $this->admin = staffUser(Role::SUPER_ADMIN);
});

/** @return array<string, mixed> */
function newStaffPayload(array $overrides = []): array
{
    return [
        'username' => 'Budi.Santoso',
        'name' => 'Budi Santoso',
        'email' => 'Budi@Dishub.Example',
        'password' => 'Awal-Rahasia-2026',
        'password_confirmation' => 'Awal-Rahasia-2026',
        'roles' => ['FINANCE'],
        ...$overrides,
    ];
}

describe('access', function () {
    it('lets Super Admin and Auditor see the user list', function (Role $role) {
        $this->actingAs(staffUser($role))
            ->get('/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Users/Index')->has('users.data'));
    })->with([Role::SUPER_ADMIN, Role::AUDITOR]);

    it('forbids the user list to roles without users.view', function (Role $role) {
        $this->actingAs(staffUser($role))->get('/users')->assertForbidden();
    })->with([Role::DISHUB_ADMIN, Role::PARKING_OPERATOR, Role::FINANCE, Role::SUPERVISOR, Role::EXECUTIVE_VIEWER]);

    it('lets only users.manage create or change users', function () {
        $auditor = staffUser(Role::AUDITOR);
        $target = staffUser(Role::FINANCE);

        $this->actingAs($auditor)->get('/users/create')->assertForbidden();
        $this->actingAs($auditor)->post('/users', newStaffPayload())->assertForbidden();
        $this->actingAs($auditor)->put("/users/{$target->id}/status", ['status' => 'SUSPENDED'])->assertForbidden();
        $this->actingAs($auditor)->put("/users/{$target->id}/password", ['password' => 'x', 'password_confirmation' => 'x'])->assertForbidden();

        expect(User::query()->where('username', 'budi.santoso')->exists())->toBeFalse()
            ->and($target->refresh()->status)->toBe(UserStatus::ACTIVE);
    });

    it('filters the list', function () {
        staffUser(Role::FINANCE)->forceFill(['username' => 'keuangan.satu'])->save();
        attendantUser();

        $this->actingAs($this->admin)
            ->get('/users?account_type=ATTENDANT')
            ->assertInertia(fn (Assert $page) => $page->has('users.data', 1)->where('users.data.0.account_type.value', 'ATTENDANT'));

        $this->actingAs($this->admin)
            ->get('/users?q=KEUANGAN')
            ->assertInertia(fn (Assert $page) => $page->has('users.data', 1)->where('users.data.0.username', 'keuangan.satu'));
    });
});

describe('create', function () {
    it('creates a staff account with normalised username and email', function () {
        $response = $this->actingAs($this->admin)->post('/users', newStaffPayload());

        $user = User::query()->where('username', 'budi.santoso')->sole();
        $response->assertRedirect("/users/{$user->id}/edit")->assertSessionHas('success');

        expect($user->email)->toBe('budi@dishub.example')
            ->and($user->account_type)->toBe(AccountType::STAFF)
            ->and($user->status)->toBe(UserStatus::ACTIVE)
            ->and($user->getRoleNames()->all())->toBe(['FINANCE'])
            ->and(Hash::check('Awal-Rahasia-2026', $user->password))->toBeTrue();

        $audit = auditOf(AuditAction::USER_CREATED)->last();
        expect($audit->actor_id)->toBe($this->admin->id)
            ->and($audit->metadata)->toBe(['roles' => ['FINANCE'], 'username' => 'budi.santoso', 'account_type' => 'STAFF'])
            ->and(json_encode($audit->metadata))->not->toContain('Awal-Rahasia');
    });

    it('validates the new account', function (array $overrides, string $field) {
        staffUser(Role::AUDITOR)->forceFill(['username' => 'budi.santoso'])->save();

        $this->actingAs($this->admin)
            ->post('/users', newStaffPayload(['username' => 'baru.sekali', ...$overrides]))
            ->assertSessionHasErrors($field);
    })->with([
        'username taken (case-insensitive)' => [['username' => 'BUDI.SANTOSO'], 'username'],
        'username format' => [['username' => 'ab'], 'username'],
        'password too short' => [['password' => 'Pendek1', 'password_confirmation' => 'Pendek1'], 'password'],
        'password without digits' => [['password' => 'TanpaAngkaSama', 'password_confirmation' => 'TanpaAngkaSama'], 'password'],
        'password mismatch' => [['password_confirmation' => 'Lain-Sekali-2026'], 'password'],
        'no role' => [['roles' => []], 'roles'],
        'attendant role on staff account' => [['roles' => ['PARKING_ATTENDANT']], 'roles.0'],
        'unknown role' => [['roles' => ['GOD_MODE']], 'roles.0'],
    ]);
});

describe('update', function () {
    it('updates profile and roles and audits each change', function () {
        $user = staffUser(Role::FINANCE);

        $this->actingAs($this->admin)
            ->put("/users/{$user->id}", ['name' => 'Nama Baru', 'email' => 'baru@dishub.example', 'roles' => ['FINANCE', 'AUDITOR']])
            ->assertRedirect()
            ->assertSessionHas('success');

        $user->refresh();
        expect($user->name)->toBe('Nama Baru')
            ->and($user->getRoleNames()->sort()->values()->all())->toBe(['AUDITOR', 'FINANCE']);

        expect(auditOf(AuditAction::USER_CHANGED)->sole()->metadata['changes']['name']['to'])->toBe('Nama Baru');
        expect(auditOf(AuditAction::USER_ROLES_CHANGED)->sole()->metadata)->toBe(['to' => ['AUDITOR', 'FINANCE'], 'from' => ['FINANCE']]);
    });

    it('does not audit a save without changes', function () {
        $user = staffUser(Role::FINANCE);

        $this->actingAs($this->admin)->put("/users/{$user->id}", ['name' => $user->name, 'email' => $user->email, 'roles' => ['FINANCE']]);

        expect(auditOf(AuditAction::USER_CHANGED))->toHaveCount(0)
            ->and(auditOf(AuditAction::USER_ROLES_CHANGED))->toHaveCount(0);
    });

    it('never removes the last active Super Admin', function () {
        $this->actingAs($this->admin)
            ->put("/users/{$this->admin->id}", ['name' => 'X', 'email' => null, 'roles' => ['AUDITOR']])
            ->assertSessionHasErrors(['roles' => 'Harus selalu ada minimal satu Super Admin yang aktif.']);

        expect($this->admin->refresh()->hasRole('SUPER_ADMIN'))->toBeTrue();
    });

    it('allows removing a Super Admin while another one stays active', function () {
        $second = staffUser(Role::SUPER_ADMIN);

        $this->actingAs($this->admin)
            ->put("/users/{$second->id}", ['name' => $second->name, 'email' => null, 'roles' => ['AUDITOR']])
            ->assertSessionHasNoErrors();

        expect($second->refresh()->getRoleNames()->all())->toBe(['AUDITOR']);
    });
});

describe('status', function () {
    it('suspends a staff account, ends its mobile sessions and records the reason', function () {
        $staff = staffUser(Role::FINANCE);

        $this->actingAs($this->admin)
            ->put("/users/{$staff->id}/status", ['status' => 'SUSPENDED', 'reason' => 'Mutasi jabatan'])
            ->assertSessionHasNoErrors();

        expect($staff->refresh()->status)->toBe(UserStatus::SUSPENDED);
        expect(auditOf(AuditAction::USER_STATUS_CHANGED)->sole()->metadata)
            ->toMatchArray(['from' => 'ACTIVE', 'to' => 'SUSPENDED', 'reason' => 'Mutasi jabatan']);
    });

    it('leaves attendant account status to the attendant registry', function () {
        $attendant = attendantUser();

        $this->actingAs($this->admin)
            ->put("/users/{$attendant->id}/status", ['status' => 'SUSPENDED', 'reason' => 'x'])
            ->assertSessionHasErrors(['status' => 'Status akun juru parkir diubah melalui halaman Juru Parkir.']);

        expect($attendant->refresh()->status)->toBe(UserStatus::ACTIVE);
    });

    it('does not let administrators deactivate themselves', function () {
        staffUser(Role::SUPER_ADMIN);

        $this->actingAs($this->admin)
            ->put("/users/{$this->admin->id}/status", ['status' => 'INACTIVE'])
            ->assertSessionHasErrors(['status' => 'Anda tidak dapat menonaktifkan akun Anda sendiri.']);

        expect($this->admin->refresh()->status)->toBe(UserStatus::ACTIVE);
    });

    it('reactivates an account', function () {
        $user = staffUser(Role::FINANCE)->forceFill(['status' => UserStatus::INACTIVE]);
        $user->save();

        $this->actingAs($this->admin)->put("/users/{$user->id}/status", ['status' => 'ACTIVE'])->assertSessionHasNoErrors();

        expect($user->refresh()->status)->toBe(UserStatus::ACTIVE);
    });
});

describe('password reset', function () {
    it('sets a new password and ends mobile sessions', function () {
        $attendant = attendantUser();
        $this->postJson('/api/v1/auth/login', [
            'username' => $attendant->username, 'password' => UserFactory::PASSWORD, 'device_uuid' => TEST_DEVICE_UUID,
        ])->assertOk();

        $this->actingAs($this->admin)
            ->put("/users/{$attendant->id}/password", ['password' => 'Baru-Rahasia-2026', 'password_confirmation' => 'Baru-Rahasia-2026'])
            ->assertSessionHasNoErrors();

        expect(Hash::check('Baru-Rahasia-2026', $attendant->refresh()->password))->toBeTrue()
            ->and(MobileRefreshToken::query()->whereNull('revoked_at')->count())->toBe(0);

        $audit = auditOf(AuditAction::USER_PASSWORD_RESET)->sole();
        expect($audit->metadata)->toBe(['revoked_mobile_sessions' => 1])
            ->and(json_encode($audit->metadata))->not->toContain('Baru-Rahasia');
    });

    it('enforces the password policy', function () {
        $user = staffUser(Role::FINANCE);

        $this->actingAs($this->admin)
            ->put("/users/{$user->id}/password", ['password' => 'lemah', 'password_confirmation' => 'lemah'])
            ->assertSessionHasErrors('password');
    });
});

describe('role matrix page', function () {
    it('shows the matrix to users with roles.view', function () {
        $this->actingAs($this->admin)
            ->get('/roles')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Roles/Index')
                ->has('roles', count(Role::cases()))
                ->where('roles.0.value', 'SUPER_ADMIN')
                ->where('roles.0.users', 1));
    });

    it('is forbidden without roles.view', function () {
        $this->actingAs(staffUser(Role::PARKING_OPERATOR))->get('/roles')->assertForbidden();
    });
});
