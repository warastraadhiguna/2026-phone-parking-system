<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Enums\AccountType;
use App\Domain\Identity\Models\User;
use Database\Seeders\DatabaseSeeder;

describe('identity:create-super-admin', function () {
    it('creates a Super Admin with a generated password shown once', function () {
        $this->artisan('identity:create-super-admin', ['username' => 'admin.pati', 'name' => 'Admin Pati', '--generate-password' => true])
            ->expectsOutputToContain('Super Admin [admin.pati] created')
            ->expectsOutputToContain('Generated password')
            ->assertSuccessful();

        $user = User::query()->where('username', 'admin.pati')->sole();
        expect($user->hasRole('SUPER_ADMIN'))->toBeTrue()
            ->and($user->account_type)->toBe(AccountType::STAFF);

        $created = auditOf(AuditAction::USER_CREATED)->sole();
        expect($created->actor_type->value)->toBe('SYSTEM');
    });

    it('asks for the password interactively and enforces the policy', function () {
        $this->artisan('identity:create-super-admin', ['username' => 'admin.pati', 'name' => 'Admin Pati'])
            ->expectsQuestion('Password (min. 10 characters, letters and digits)', 'lemah')
            ->assertFailed();

        expect(User::query()->where('username', 'admin.pati')->exists())->toBeFalse();
    });

    it('refuses a duplicate username', function () {
        syncRoles();
        staffUser();
        $existing = User::query()->firstOrFail();

        $this->artisan('identity:create-super-admin', ['username' => $existing->username, 'name' => 'X', '--generate-password' => true])
            ->assertFailed();
    });
});

describe('DatabaseSeeder', function () {
    it('creates one demo account per role in local/testing', function () {
        config(['identity.dev_seed_password' => 'Demo-Test-2026']);

        $this->seed(DatabaseSeeder::class);

        expect(User::count())->toBe(8)
            ->and(User::query()->where('account_type', AccountType::ATTENDANT->value)->count())->toBe(1); // code depends on the (non-transactional) sequence

        $this->seed(DatabaseSeeder::class); // idempotent
        expect(User::count())->toBe(8);
    });

    it('refuses to run in production', function () {
        app()->detectEnvironment(fn () => 'production');

        // Invoke directly: `db:seed` itself would stop at its production confirmation prompt.
        expect(fn () => app(DatabaseSeeder::class)->setContainer(app())->__invoke())->toThrow(RuntimeException::class);
        expect(User::count())->toBe(0);
    });
});
