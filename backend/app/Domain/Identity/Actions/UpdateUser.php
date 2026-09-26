<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Internal\UserRules;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Updates profile fields and roles. Username and account type are immutable after creation.
 */
final class UpdateUser
{
    public function __construct(
        private readonly UserRules $rules,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * @param  list<Role>  $roles
     */
    public function handle(User $user, string $name, ?string $email, array $roles, ?User $actor): User
    {
        $this->rules->assertRolesFit($user->account_type, $roles);

        return DB::transaction(function () use ($user, $name, $email, $roles, $actor) {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);

            $newRoleNames = array_map(fn (Role $r) => $r->value, $roles);
            sort($newRoleNames);
            $oldRoleNames = $user->getRoleNames()->sort()->values()->all();

            $this->rules->assertNotRemovingLastSuperAdmin(
                $user,
                stillActive: $user->isActive(),
                stillSuperAdmin: in_array(Role::SUPER_ADMIN, $roles, true),
            );

            $user->fill(['name' => trim($name), 'email' => $email]);
            $changes = [];
            foreach ($user->getDirty() as $field => $new) {
                $changes[$field] = ['from' => $user->getOriginal($field), 'to' => $new];
            }
            $user->save();

            if ($changes !== []) {
                $this->audit->handle(AuditAction::USER_CHANGED, $actor, 'user', $user->id, ['changes' => $changes]);
            }

            if ($oldRoleNames !== $newRoleNames) {
                $user->syncRoles($newRoleNames);
                $this->audit->handle(AuditAction::USER_ROLES_CHANGED, $actor, 'user', $user->id, [
                    'from' => $oldRoleNames,
                    'to' => $newRoleNames,
                ]);
            }

            return $user->refresh();
        });
    }
}
