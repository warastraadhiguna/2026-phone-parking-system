<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Enums\AccountType;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Internal\UserRules;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates a staff or attendant account. Attendant accounts are normally created by the
 * ParkingAttendant module (Phase 2) through this Action.
 */
final class CreateUser
{
    public function __construct(
        private readonly UserRules $rules,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * @param  list<Role>  $roles
     */
    public function handle(
        string $username,
        string $name,
        ?string $email,
        #[\SensitiveParameter] string $password,
        AccountType $accountType,
        array $roles,
        ?User $actor,
    ): User {
        $this->rules->assertRolesFit($accountType, $roles);

        return DB::transaction(function () use ($username, $name, $email, $password, $accountType, $roles, $actor) {
            $user = User::create([
                'username' => $username,
                'name' => trim($name),
                'email' => $email,
                'password' => $password,
                'account_type' => $accountType,
                'status' => UserStatus::ACTIVE,
            ]);
            $user->syncRoles(array_map(fn (Role $r) => $r->value, $roles));

            $this->audit->handle(AuditAction::USER_CREATED, $actor, 'user', $user->id, [
                'username' => $user->username,
                'account_type' => $accountType->value,
                'roles' => array_map(fn (Role $r) => $r->value, $roles),
            ]);

            return $user;
        });
    }
}
