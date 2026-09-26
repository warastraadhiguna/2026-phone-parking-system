<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Makes the database match the code-defined matrix (Role::permissions()). Idempotent.
 * Permissions no longer in the catalogue are removed. Changes are audited.
 */
final class SyncRolePermissions
{
    private const GUARD = 'web';

    public function __construct(
        private readonly PermissionRegistrar $registrar,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * @return array{created_permissions: list<string>, removed_permissions: list<string>, changed_roles: array<string, array{added: list<string>, removed: list<string>}>}
     */
    public function handle(?User $actor = null): array
    {
        $result = DB::transaction(function () use ($actor) {
            $wanted = array_map(fn (Permission $p) => $p->value, Permission::cases());
            $existing = PermissionModel::query()->where('guard_name', self::GUARD)->pluck('name')->all();

            $created = array_values(array_diff($wanted, $existing));
            foreach ($created as $name) {
                PermissionModel::create(['name' => $name, 'guard_name' => self::GUARD]);
            }

            $removed = array_values(array_diff($existing, $wanted));
            PermissionModel::query()->where('guard_name', self::GUARD)->whereIn('name', $removed)->delete();

            $this->registrar->forgetCachedPermissions();

            $changedRoles = [];
            foreach (Role::cases() as $role) {
                $model = RoleModel::findOrCreate($role->value, self::GUARD);
                $before = $model->permissions()->pluck('name')->all();
                $after = array_map(fn (Permission $p) => $p->value, $role->permissions());

                $added = array_values(array_diff($after, $before));
                $lost = array_values(array_diff($before, $after));
                if ($added !== [] || $lost !== []) {
                    $model->syncPermissions($after);
                    $changedRoles[$role->value] = ['added' => $added, 'removed' => $lost];
                }
            }

            $result = [
                'created_permissions' => $created,
                'removed_permissions' => $removed,
                'changed_roles' => $changedRoles,
            ];

            if ($created !== [] || $removed !== [] || $changedRoles !== []) {
                $this->audit->handle(AuditAction::ROLE_PERMISSIONS_SYNCED, $actor, 'role_matrix', null, $result);
            }

            return $result;
        });

        $this->registrar->forgetCachedPermissions();

        return $result;
    }
}
