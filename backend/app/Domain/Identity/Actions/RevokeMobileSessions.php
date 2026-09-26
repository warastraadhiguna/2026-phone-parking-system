<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\RevokeReason;
use App\Domain\Identity\Internal\MobileTokenIssuer;
use App\Domain\Identity\Models\User;

/**
 * Ends every mobile session of a user (optionally on one device only).
 * Public so other modules can call it, e.g. Device when a device is revoked or lost (Phase 2).
 * Callers record their own audit event describing why.
 */
final class RevokeMobileSessions
{
    public function __construct(private readonly MobileTokenIssuer $tokens) {}

    public function handle(User $user, RevokeReason $reason, ?string $deviceUuid = null): int
    {
        return $this->tokens->revokeUser($user, $reason, $deviceUuid);
    }
}
