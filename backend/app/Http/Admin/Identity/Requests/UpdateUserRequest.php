<?php

namespace App\Http\Admin\Identity\Requests;

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateUserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => $this->filled('email') ? mb_strtolower(trim((string) $this->input('email'))) : null,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:191', Rule::unique('users', 'email')->ignore($user->id)],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in(array_map(fn (Role $r) => $r->value, Role::forAccountType($user->account_type)))],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => 'nama', 'email' => 'email', 'roles' => 'peran'];
    }

    /** @return list<Role> */
    public function roles(): array
    {
        return array_values(array_map(fn (string $r) => Role::from($r), (array) $this->input('roles', [])));
    }
}
