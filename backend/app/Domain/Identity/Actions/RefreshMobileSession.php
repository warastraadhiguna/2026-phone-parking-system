<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Contracts\MobileDeviceGate;
use App\Domain\Identity\Data\MobileTokenPair;
use App\Domain\Identity\Enums\RevokeReason;
use App\Domain\Identity\Exceptions\AccountDisabled;
use App\Domain\Identity\Exceptions\InvalidRefreshToken;
use App\Domain\Identity\Internal\MobileTokenIssuer;
use App\Domain\Identity\Models\MobileRefreshToken;
use App\Domain\Identity\Models\User;
use App\Support\Errors\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Rotates a refresh token (ADR-0005).
 *
 * 1. The presented token must exist, belong to the same device, not be revoked or expired.
 * 2. First use: mark it used, issue a new pair in the same family, drop the old access token.
 * 3. Already used:
 *    - within the grace window and its replacement never used → treated as a retry after a lost
 *      response: the unused replacement is superseded and a fresh pair is issued;
 *    - otherwise → possible token theft: the whole family is revoked and audited.
 *
 * Revocations are committed before the error is thrown, so they are never rolled back.
 */
final class RefreshMobileSession
{
    public function __construct(
        private readonly MobileTokenIssuer $tokens,
        private readonly RecordAuditEvent $audit,
        private readonly MobileDeviceGate $devices,
    ) {}

    public function handle(#[\SensitiveParameter] string $plainRefreshToken, string $deviceUuid): MobileTokenPair
    {
        /** @var MobileTokenPair|ApiException $outcome */
        $outcome = DB::transaction(function () use ($plainRefreshToken, $deviceUuid): MobileTokenPair|ApiException {
            $token = MobileRefreshToken::query()
                ->where('token_hash', MobileRefreshToken::hash($plainRefreshToken))
                ->lockForUpdate()
                ->first();

            if ($token === null || ! hash_equals($token->device_uuid, $deviceUuid) || $token->isRevoked() || $token->isExpired()) {
                return new InvalidRefreshToken;
            }

            /** @var User $user */
            $user = $token->user;
            if (! $user->isActive() || ! $user->isAttendant()) {
                $this->tokens->revokeFamily($token->family_id, RevokeReason::ACCOUNT_DISABLED);

                return new AccountDisabled;
            }

            try {
                $this->devices->assertStillAdmitted($user, $token->device_uuid);
            } catch (ApiException $e) {
                $this->tokens->revokeFamily($token->family_id, RevokeReason::DEVICE_NOT_ALLOWED);

                return $e;
            }

            $isRetry = false;
            if ($token->used_at !== null) {
                $replacement = $token->replacedBy()->lockForUpdate()->first();

                if (! $this->isGraceRetry($token, $replacement)) {
                    $revoked = $this->tokens->revokeFamily($token->family_id, RevokeReason::REUSE_DETECTED);
                    $this->audit->handle(
                        AuditAction::REFRESH_TOKEN_REUSE_DETECTED,
                        $user,
                        'mobile_session',
                        $token->family_id,
                        ['refresh_token_id' => $token->id, 'revoked_tokens' => $revoked],
                        deviceUuid: $deviceUuid,
                    );

                    return new InvalidRefreshToken;
                }

                /** @var MobileRefreshToken $replacement */
                $this->tokens->revoke($replacement, RevokeReason::SUPERSEDED);
                $isRetry = true;
            }

            $issued = $this->tokens->issue($user, $token->device_uuid, $token->family_id);

            $this->tokens->retire($token, $issued->refreshToken);

            $this->audit->handle(
                AuditAction::TOKEN_REFRESHED,
                $user,
                'mobile_session',
                $token->family_id,
                ['refresh_token_id' => $issued->refreshToken->id, 'retry' => $isRetry],
                deviceUuid: $deviceUuid,
            );

            return $issued->pair;
        });

        if ($outcome instanceof ApiException) {
            throw $outcome;
        }

        return $outcome;
    }

    private function isGraceRetry(MobileRefreshToken $token, ?MobileRefreshToken $replacement): bool
    {
        $grace = (int) config('identity.mobile.refresh_reuse_grace_seconds');

        return $replacement !== null
            && $replacement->used_at === null
            && ! $replacement->isRevoked()
            && $token->used_at !== null
            && $token->used_at->addSeconds($grace)->isFuture();
    }
}
