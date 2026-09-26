<?php

namespace App\Domain\Identity\Console;

use App\Domain\Identity\Actions\CreateUser;
use App\Domain\Identity\Actions\SyncRolePermissions;
use App\Domain\Identity\Enums\AccountType;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Exceptions\UserRuleViolation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Bootstraps a Super Admin account (first install or recovery). No credentials live in code:
 * the password is typed interactively, or generated and shown exactly once.
 */
final class CreateSuperAdminCommand extends Command
{
    protected $signature = 'identity:create-super-admin
        {username : Login name (lower-case letters, digits, . _ -)}
        {name : Full name}
        {--email= : Optional e-mail address}
        {--generate-password : Generate a strong password and print it once (for non-interactive use)}';

    protected $description = 'Create a Super Admin account';

    public function handle(CreateUser $createUser, SyncRolePermissions $sync): int
    {
        $sync->handle();

        $password = $this->option('generate-password')
            ? Str::password(20)
            : (string) $this->secret('Password (min. 10 characters, letters and digits)');

        $validator = Validator::make(
            ['username' => $this->argument('username'), 'email' => $this->option('email'), 'password' => $password],
            [
                'username' => ['required', 'regex:/^[a-z0-9][a-z0-9._-]{2,49}$/', 'unique:users,username'],
                'email' => ['nullable', 'email', 'unique:users,email'],
                'password' => ['required', Password::defaults()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        try {
            $user = $createUser->handle(
                (string) $this->argument('username'),
                (string) $this->argument('name'),
                $this->option('email') ? (string) $this->option('email') : null,
                $password,
                AccountType::STAFF,
                [Role::SUPER_ADMIN],
                actor: null,
            );
        } catch (UserRuleViolation $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Super Admin [{$user->username}] created (id {$user->id}).");
        if ($this->option('generate-password')) {
            $this->warn('Generated password (shown once, store it securely): '.$password);
        }

        return self::SUCCESS;
    }
}
