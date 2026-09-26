<?php

namespace App\Domain\Identity\Internal;

use App\Domain\Identity\Data\MobileTokenName;
use App\Domain\Identity\Data\MobileTokenPair;
use App\Domain\Identity\Enums\RevokeReason;
use App\Domain\Identity\Models\MobileRefreshToken;
use App\Domain\Identity\Models\User;
use App\Support\RequestContext\RequestContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Creates and revokes mobile access/refresh tokens. Callers own the DB transaction.
 */
final class MobileTokenIssuer
{
    private const REFRESH_TOKEN_PREFIX = 'pprt_';

    public function issue(User $user, string $deviceUuid, string $familyId): MobileTokenIssue
    {
        $now = CarbonImmutable::now();
        $accessExpiresAt = $now->addMinutes((int) config('identity.mobile.access_token_ttl_minutes'));
        $refreshExpiresAt = $now->addDays((int) config('identity.mobile.refresh_token_ttl_days'));

        $access = $user->createToken(MobileTokenName::for($deviceUuid), [MobileTokenName::ABILITY], $accessExpiresAt);

        $plainRefresh = self::REFRESH_TOKEN_PREFIX.Str::random(64);
        $refresh = MobileRefreshToken::create([
            'user_id' => $user->id,
            'family_id' => $familyId,
            'token_hash' => MobileRefreshToken::hash($plainRefresh),
            'device_uuid' => $deviceUuid,
            'access_token_id' => $access->accessToken->getKey(),
            'expires_at' => $refreshExpiresAt,
            'created_ip' => RequestContext::clientIp(),
        ]);

        return new MobileTokenIssue(
            $refresh,
            new MobileTokenPair($access->plainTextToken, $accessExpiresAt, $plainRefresh, $refreshExpiresAt),
        );
    }

    /**
     * Marks a refresh token as used and replaced (normal rotation) and deletes its access token.
     * A retired token is not revoked: presenting it again is detected as reuse.
     */
    public function retire(MobileRefreshToken $token, MobileRefreshToken $replacement): void
    {
        $token->forceFill([
            'used_at' => $token->used_at ?? now(),
            'replaced_by_id' => $replacement->id,
        ])->save();
        $this->deleteAccessTokens([$token->access_token_id]);
    }

    /** Revokes one refresh token and deletes its access token. */
    public function revoke(MobileRefreshToken $token, RevokeReason $reason): void
    {
        if ($token->revoked_at === null) {
            $token->forceFill(['revoked_at' => now(), 'revoke_reason' => $reason->value])->save();
        }
        $this->deleteAccessTokens([$token->access_token_id]);
    }

    /** Revokes every token of a family (one login on one device) and deletes their access tokens. */
    public function revokeFamily(string $familyId, RevokeReason $reason): int
    {
        return $this->revokeWhere(MobileRefreshToken::query()->where('family_id', $familyId), $reason);
    }

    /** Revokes every mobile session of a user, optionally only on one device. */
    public function revokeUser(User $user, RevokeReason $reason, ?string $deviceUuid = null): int
    {
        $query = MobileRefreshToken::query()->where('user_id', $user->id);
        if ($deviceUuid !== null) {
            $query->where('device_uuid', $deviceUuid);
        }

        $revoked = $this->revokeWhere($query, $reason);

        // Also drop any access token not linked to a refresh token (defensive).
        $user->tokens()->where('name', 'like', MobileTokenName::likePattern($deviceUuid))->delete();

        return $revoked;
    }

    /** @param  Builder<MobileRefreshToken>  $query */
    private function revokeWhere($query, RevokeReason $reason): int
    {
        $accessTokenIds = (clone $query)->whereNotNull('access_token_id')->pluck('access_token_id')->all();

        $revoked = (clone $query)->whereNull('revoked_at')->update(['revoked_at' => now(), 'revoke_reason' => $reason->value]);
        $this->deleteAccessTokens($accessTokenIds);

        return $revoked;
    }

    /** @param  array<int, int|null>  $ids */
    private function deleteAccessTokens(array $ids): void
    {
        $ids = array_values(array_filter($ids));
        if ($ids !== []) {
            PersonalAccessToken::query()->whereKey($ids)->delete();
        }
    }
}
