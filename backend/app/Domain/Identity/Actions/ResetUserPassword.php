<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Enums\RevokeReason;
use App\Domain\Identity\Internal\MobileTokenIssuer;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * An administrator sets a new password for a user. All mobile sessions of that user end.
 * There is no self-service reset by e-mail in the MVP.
 */
final class ResetUserPassword
{
    public function __construct(
        private readonly MobileTokenIssuer $tokens,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $user, #[\SensitiveParameter] string $newPassword, ?User $actor): void
    {
        DB::transaction(function () use ($user, $newPassword, $actor) {
            $user->forceFill(['password' => $newPassword])->save();
            $revoked = $this->tokens->revokeUser($user, RevokeReason::PASSWORD_RESET);

            $this->audit->handle(AuditAction::USER_PASSWORD_RESET, $actor, 'user', $user->id, [
                'revoked_mobile_sessions' => $revoked,
            ]);
        });
    }
}
