<?php

namespace App\Http\Admin\Identity\Requests;

use App\Domain\Identity\Enums\AccountType;
use App\Domain\Identity\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Phase 1 creates staff accounts only; attendant accounts come with attendant registration (Phase 2). */
final class StoreUserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'username' => mb_strtolower(trim((string) $this->input('username'))),
            'email' => $this->filled('email') ? mb_strtolower(trim((string) $this->input('email'))) : null,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9._-]{2,49}$/', 'unique:users,username'],
            'name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:191', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in(array_map(fn (Role $r) => $r->value, Role::forAccountType(AccountType::STAFF)))],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['username' => 'username', 'name' => 'nama', 'email' => 'email', 'password' => 'kata sandi', 'roles' => 'peran'];
    }

    /** @return list<Role> */
    public function roles(): array
    {
        return array_values(array_map(fn (string $r) => Role::from($r), (array) $this->input('roles', [])));
    }
}
