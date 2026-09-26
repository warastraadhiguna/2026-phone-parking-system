<?php

namespace App\Domain\Identity\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A rotating refresh token for the Android app (ADR-0005). Only the SHA-256 hash is stored.
 * Written exclusively by the Identity mobile-session Actions.
 *
 * @property int $id
 * @property int $user_id
 * @property string $family_id
 * @property string $token_hash
 * @property string $device_uuid
 * @property int|null $access_token_id
 * @property int|null $replaced_by_id
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $used_at
 * @property CarbonImmutable|null $revoked_at
 * @property string|null $revoke_reason
 * @property string|null $created_ip
 * @property CarbonImmutable $created_at
 */
class MobileRefreshToken extends Model
{
    use Prunable;

    public const UPDATED_AT = null;

    /** Keep expired/revoked tokens this long for investigation, then prune. */
    private const RETENTION_DAYS = 90;

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<self, $this> */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        $cutoff = now()->subDays(self::RETENTION_DAYS);

        return static::query()->where(fn (Builder $q) => $q
            ->where('expires_at', '<', $cutoff)
            ->orWhere('revoked_at', '<', $cutoff));
    }
}
