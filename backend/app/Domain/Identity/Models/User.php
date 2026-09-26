<?php

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\AccountType;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Enums\UserStatus;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * An account for staff (admin web) or an attendant (mobile).
 *
 * Other modules may read users and relate to them. Changes go through the Identity Actions
 * (CreateUser, UpdateUser, ChangeUserStatus, ResetUserPassword), which also write the audit trail.
 *
 * @property int $id
 * @property string $username
 * @property string $name
 * @property string|null $email
 * @property string $password
 * @property AccountType $account_type
 * @property UserStatus $status
 * @property CarbonImmutable|null $last_login_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles;

    /** Roles and permissions are registered for the session guard only (see ADR-0005). */
    protected string $guard_name = 'web';

    protected $fillable = ['username', 'name', 'email', 'password', 'account_type', 'status', 'last_login_at'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'account_type' => AccountType::class,
            'status' => UserStatus::class,
            'last_login_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /**
     * Usernames are case-insensitive: always stored lower-case and trimmed.
     *
     * @return Attribute<string, string>
     */
    protected function username(): Attribute
    {
        return Attribute::set(fn (string $value) => mb_strtolower(trim($value)));
    }

    /** @return Attribute<string|null, string|null> */
    protected function email(): Attribute
    {
        return Attribute::set(fn (?string $value) => $value === null || trim($value) === '' ? null : mb_strtolower(trim($value)));
    }

    /** @return HasMany<MobileRefreshToken, $this> */
    public function mobileRefreshTokens(): HasMany
    {
        return $this->hasMany(MobileRefreshToken::class);
    }

    public function isActive(): bool
    {
        return $this->status->canLogIn();
    }

    public function isStaff(): bool
    {
        return $this->account_type === AccountType::STAFF;
    }

    public function isAttendant(): bool
    {
        return $this->account_type === AccountType::ATTENDANT;
    }

    public function hasRoleEnum(Role $role): bool
    {
        return $this->hasRole($role->value);
    }

    /** @return list<Role> */
    public function roleEnums(): array
    {
        return array_values(array_filter(array_map(
            fn (string $name) => Role::tryFrom($name),
            $this->getRoleNames()->all(),
        )));
    }

    /** @return list<string> */
    public function permissionNames(): array
    {
        $names = array_map('strval', $this->getAllPermissions()->pluck('name')->all());
        sort($names);

        return $names;
    }
}
