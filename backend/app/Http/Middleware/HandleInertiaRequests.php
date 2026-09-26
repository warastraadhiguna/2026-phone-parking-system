<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Props shared with every page. Keep this minimal: anything here is sent on every request.
     * The TypeScript counterpart is resources/js/types/index.ts (SharedProps).
     *
     * Permissions are sent only so the UI can hide what the user cannot use;
     * the server enforces every permission independently.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'app' => [
                'name' => config('app.name'),
                'environment' => app()->environment(),
            ],
            'auth' => [
                'user' => $user instanceof User ? [
                    'id' => $user->id,
                    'username' => $user->username,
                    'name' => $user->name,
                    'roles' => array_map(fn (Role $r) => ['value' => $r->value, 'label' => $r->label()], $user->roleEnums()),
                    'permissions' => $user->permissionNames(),
                ] : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
            ],
        ];
    }
}
