<?php

namespace Database\Factories;

use App\Domain\Identity\Enums\AccountType;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * Test/dev factory. Roles must exist first (`identity:sync-roles` / SyncRolePermissions).
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /** Password used by every factory user in tests. */
    public const PASSWORD = 'Rahasia-Test-123';

    protected static ?string $passwordHash = null;

    public function definition(): array
    {
        return [
            'username' => fake()->unique()->bothify('user.####??'),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$passwordHash ??= Hash::make(self::PASSWORD),
            'account_type' => AccountType::STAFF,
            'status' => UserStatus::ACTIVE,
        ];
    }

    public function staff(Role ...$roles): static
    {
        return $this->state(['account_type' => AccountType::STAFF])
            ->afterCreating(fn (User $user) => $user->syncRoles(array_map(fn (Role $r) => $r->value, $roles)));
    }

    public function attendant(): static
    {
        return $this->state(['account_type' => AccountType::ATTENDANT, 'email' => null])
            ->afterCreating(fn (User $user) => $user->syncRoles([Role::PARKING_ATTENDANT->value]));
    }

    public function status(UserStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
