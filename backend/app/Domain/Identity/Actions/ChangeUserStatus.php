<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Enums\RevokeReason;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Internal\MobileTokenIssuer;
use App\Domain\Identity\Internal\UserRules;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Activates, deactivates or suspends an account. Deactivation ends mobile sessions immediately;
 * admin web sessions are ended on their next request (EnsureActiveStaff middleware).
 */
final class ChangeUserStatus
{
    public function __construct(
        private readonly UserRules $rules,
        private readonly MobileTokenIssuer $tokens,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $user, UserStatus $status, ?string $reason, ?User $actor): User
    {
        return DB::transaction(function () use ($user, $status, $reason, $actor) {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $from = $user->status;

            if ($from === $status) {
                return $user;
            }

            if (! $status->canLogIn()) {
                $this->rules->assertNotSelf($user, $actor, 'status', 'Anda tidak dapat menonaktifkan akun Anda sendiri.');
                $this->rules->assertNotRemovingLastSuperAdmin($user, stillActive: false, stillSuperAdmin: $user->hasRoleEnum(Role::SUPER_ADMIN));
            }

            $user->forceFill(['status' => $status])->save();

            $revoked = $status->canLogIn() ? 0 : $this->tokens->revokeUser($user, RevokeReason::ACCOUNT_DISABLED);

            $this->audit->handle(AuditAction::USER_STATUS_CHANGED, $actor, 'user', $user->id, array_filter([
                'from' => $from->value,
                'to' => $status->value,
                'reason' => $reason,
                'revoked_mobile_sessions' => $revoked ?: null,
            ], fn ($v) => $v !== null));

            return $user;
        });
    }
}
