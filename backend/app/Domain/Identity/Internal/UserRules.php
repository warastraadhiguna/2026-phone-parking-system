<?php

namespace App\Domain\Identity\Internal;

use App\Domain\Identity\Enums\AccountType;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Exceptions\UserRuleViolation;
use App\Domain\Identity\Models\User;

/**
 * Invariants of user administration, shared by the Identity Actions.
 */
final class UserRules
{
    /** @param  list<Role>  $roles */
    public function assertRolesFit(AccountType $type, array $roles): void
    {
        if ($roles === []) {
            throw new UserRuleViolation('roles', 'Pilih minimal satu peran.');
        }

        foreach ($roles as $role) {
            if ($role->accountType() !== $type) {
                throw new UserRuleViolation('roles', "Peran {$role->label()} tidak dapat diberikan ke akun {$type->label()}.");
            }
        }
    }

    /**
     * The system must always keep at least one active Super Admin.
     * Call inside a transaction: the Super Admin rows are locked to serialise concurrent changes.
     */
    public function assertNotRemovingLastSuperAdmin(User $user, bool $stillActive, bool $stillSuperAdmin): void
    {
        if (! $user->hasRoleEnum(Role::SUPER_ADMIN) || ($stillActive && $stillSuperAdmin)) {
            return;
        }

        // PostgreSQL forbids FOR UPDATE with aggregates, so lock the rows and count them in PHP.
        // @phpstan-ignore larastan.noUnnecessaryCollectionCall
        $otherActiveSuperAdmins = User::role(Role::SUPER_ADMIN->value)
            ->where('status', UserStatus::ACTIVE->value)
            ->whereKeyNot($user->getKey())
            ->lockForUpdate()
            ->pluck('users.id')
            ->count();

        if ($otherActiveSuperAdmins === 0) {
            throw new UserRuleViolation(
                $stillSuperAdmin ? 'status' : 'roles',
                'Harus selalu ada minimal satu Super Admin yang aktif.',
            );
        }
    }

    public function assertNotSelf(User $user, ?User $actor, string $field, string $message): void
    {
        if ($actor !== null && $actor->is($user)) {
            throw new UserRuleViolation($field, $message);
        }
    }
}
