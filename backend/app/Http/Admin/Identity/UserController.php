<?php

namespace App\Http\Admin\Identity;

use App\Domain\Identity\Actions\CreateUser;
use App\Domain\Identity\Actions\UpdateUser;
use App\Domain\Identity\Enums\AccountType;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Http\Admin\Identity\Requests\StoreUserRequest;
use App\Http\Admin\Identity\Requests\UpdateUserRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class UserController
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'account_type' => ['nullable', Rule::enum(AccountType::class)],
            'status' => ['nullable', Rule::enum(UserStatus::class)],
        ]);

        $users = User::query()
            ->with('roles:id,name')
            ->when($filters['q'] ?? null, fn ($q, string $term) => $q->where(fn ($w) => $w
                ->where('username', 'ilike', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('name', 'ilike', '%'.addcslashes($term, '%_\\').'%')))
            ->when($filters['account_type'] ?? null, fn ($q, string $type) => $q->where('account_type', $type))
            ->when($filters['status'] ?? null, fn ($q, string $status) => $q->where('status', $status))
            ->orderBy('username')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $user) => $this->row($user));

        return Inertia::render('Users/Index', [
            'users' => $users,
            'filters' => [
                'q' => $filters['q'] ?? '',
                'account_type' => $filters['account_type'] ?? '',
                'status' => $filters['status'] ?? '',
            ],
            'options' => $this->options(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Users/Create', [
            'roleOptions' => $this->roleOptions(AccountType::STAFF),
        ]);
    }

    public function store(StoreUserRequest $request, CreateUser $createUser): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $user = $createUser->handle(
            (string) $request->string('username'),
            (string) $request->string('name'),
            $request->input('email'),
            (string) $request->string('password'),
            AccountType::STAFF,
            $request->roles(),
            $actor,
        );

        return redirect()->route('users.edit', $user)->with('success', "Pengguna {$user->username} berhasil dibuat.");
    }

    public function edit(Request $request, User $user): Response
    {
        $user->load('roles:id,name');
        /** @var User $actor */
        $actor = $request->user();

        return Inertia::render('Users/Edit', [
            'user' => [
                ...$this->row($user),
                'created_at' => $user->created_at->toIso8601String(),
            ],
            'roleOptions' => $this->roleOptions($user->account_type),
            'statusOptions' => $this->options()['statuses'],
            'isSelf' => $actor->is($user),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user, UpdateUser $updateUser): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $updateUser->handle($user, (string) $request->string('name'), $request->input('email'), $request->roles(), $actor);

        return back()->with('success', 'Perubahan disimpan.');
    }

    /** @return array<string, mixed> */
    private function row(User $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'name' => $user->name,
            'email' => $user->email,
            'account_type' => ['value' => $user->account_type->value, 'label' => $user->account_type->label()],
            'status' => ['value' => $user->status->value, 'label' => $user->status->label()],
            'roles' => array_map(fn (Role $r) => ['value' => $r->value, 'label' => $r->label()], $user->roleEnums()),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
        ];
    }

    /** @return list<array{value: string, label: string}> */
    private function roleOptions(AccountType $type): array
    {
        return array_map(fn (Role $r) => ['value' => $r->value, 'label' => $r->label()], Role::forAccountType($type));
    }

    /** @return array{account_types: list<array{value: string, label: string}>, statuses: list<array{value: string, label: string}>} */
    private function options(): array
    {
        return [
            'account_types' => array_map(fn (AccountType $t) => ['value' => $t->value, 'label' => $t->label()], AccountType::cases()),
            'statuses' => array_map(fn (UserStatus $s) => ['value' => $s->value, 'label' => $s->label()], UserStatus::cases()),
        ];
    }
}
