<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\ActorType;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Enums\AccountType;
use App\Domain\Identity\Exceptions\AccountDisabled;
use App\Domain\Identity\Exceptions\InvalidCredentials;
use App\Domain\Identity\Models\User;
use App\Support\Errors\ApiException;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Verifies a username/password for one channel and records the outcome in the audit trail.
 *
 * - Unknown user, wrong password and wrong account type all fail the same way (InvalidCredentials)
 *   so the response never reveals which accounts exist or which channel they belong to.
 * - A correct password on a non-active account fails with AccountDisabled.
 * - $admit may refuse the login with an ApiException (recorded as LOGIN_FAILED).
 * - Establishing the session or tokens is the caller's job.
 */
final class AuthenticateUser
{
    public const CHANNEL_ADMIN_WEB = 'admin_web';

    public const CHANNEL_MOBILE = 'mobile';

    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(
        string $username,
        #[\SensitiveParameter] string $password,
        AccountType $accountType,
        string $channel,
        ?string $deviceUuid = null,
        ?Closure $admit = null,
    ): User {
        $username = mb_strtolower(trim($username));
        $user = User::query()->where('username', $username)->first();

        // Hash even for unknown users so response time does not reveal whether the username exists.
        $passwordValid = Hash::check($password, $user->password ?? $this->dummyHash());

        if ($user === null || ! $passwordValid || $user->account_type !== $accountType) {
            $reason = match (true) {
                $user === null => 'unknown_user',
                ! $passwordValid => 'wrong_password',
                default => 'wrong_account_type',
            };
            $this->recordFailure($username, $channel, $reason, $user, $deviceUuid);

            throw new InvalidCredentials;
        }

        if (! $user->isActive()) {
            $this->recordFailure($username, $channel, 'account_'.strtolower($user->status->value), $user, $deviceUuid);

            throw new AccountDisabled;
        }

        // Channel-specific admission (e.g. device binding) runs before the login is recorded.
        if ($admit !== null) {
            try {
                $admit($user);
            } catch (ApiException $e) {
                $this->recordFailure($username, $channel, strtolower($e->errorCode->value), $user, $deviceUuid);

                throw $e;
            }
        }

        DB::transaction(function () use ($user, $password, $channel, $deviceUuid) {
            $attributes = ['last_login_at' => now()];
            if (Hash::needsRehash($user->password)) {
                $attributes['password'] = $password;
            }
            $user->forceFill($attributes)->save();

            $this->audit->handle(AuditAction::LOGIN, $user, 'user', $user->id, ['channel' => $channel], deviceUuid: $deviceUuid);
        });

        return $user;
    }

    private function recordFailure(string $username, string $channel, string $reason, ?User $user, ?string $deviceUuid): void
    {
        $this->audit->handle(
            AuditAction::LOGIN_FAILED,
            actor: null,
            entityType: $user !== null ? 'user' : null,
            entityId: $user?->id,
            metadata: ['username' => mb_substr($username, 0, 50), 'channel' => $channel, 'reason' => $reason],
            actorType: ActorType::ANONYMOUS,
            deviceUuid: $deviceUuid,
        );
    }

    private function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make('timing-equaliser');
    }
}
