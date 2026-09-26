<?php

namespace App\Http\Admin\Identity;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only view of the role → permission matrix (defined in code, see Role::permissions()).
 */
final class RoleController
{
    public function __invoke(): Response
    {
        $userCounts = User::query()
            ->join('model_has_roles', fn ($j) => $j->on('model_has_roles.model_id', '=', 'users.id')
                ->where('model_has_roles.model_type', (new User)->getMorphClass()))
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->groupBy('roles.name')
            ->selectRaw('roles.name as role, count(*) as total')
            ->pluck('total', 'role');

        return Inertia::render('Roles/Index', [
            'roles' => array_map(fn (Role $role) => [
                'value' => $role->value,
                'label' => $role->label(),
                'account_type' => $role->accountType()->label(),
                'users' => (int) ($userCounts[$role->value] ?? 0),
                'permissions' => array_map(fn (Permission $p) => $p->value, $role->permissions()),
            ], Role::cases()),
            'permissions' => array_map(fn (Permission $p) => $p->value, Permission::cases()),
        ]);
    }
}
