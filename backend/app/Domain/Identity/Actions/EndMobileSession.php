<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Data\MobileTokenName;
use App\Domain\Identity\Enums\RevokeReason;
use App\Domain\Identity\Internal\MobileTokenIssuer;
use App\Domain\Identity\Models\MobileRefreshToken;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Mobile logout: revokes the session (refresh token family) behind the current access token.
 */
final class EndMobileSession
{
    public function __construct(
        private readonly MobileTokenIssuer $tokens,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $user, PersonalAccessToken $accessToken): void
    {
        DB::transaction(function () use ($user, $accessToken) {
            $refresh = MobileRefreshToken::query()->where('access_token_id', $accessToken->getKey())->first();
            $deviceUuid = MobileTokenName::deviceUuid($accessToken->name);

            if ($refresh !== null) {
                $this->tokens->revokeFamily($refresh->family_id, RevokeReason::LOGOUT);
            }
            $accessToken->delete();

            $this->audit->handle(
                AuditAction::LOGOUT,
                $user,
                'mobile_session',
                $refresh?->family_id,
                ['channel' => AuthenticateUser::CHANNEL_MOBILE],
                deviceUuid: $deviceUuid,
            );
        });
    }
}
