<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Contracts\MobileDeviceGate;
use App\Domain\Identity\Data\MobileDeviceInfo;
use App\Domain\Identity\Data\MobileLogin;
use App\Domain\Identity\Enums\AccountType;
use App\Domain\Identity\Enums\RevokeReason;
use App\Domain\Identity\Internal\MobileTokenIssuer;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Attendant login on the Android app: verifies credentials, admits the device (MobileDeviceGate)
 * and issues an access + refresh token pair bound to it. A new login on the same device replaces
 * its previous session.
 */
final class StartMobileSession
{
    public function __construct(
        private readonly AuthenticateUser $authenticate,
        private readonly MobileTokenIssuer $tokens,
        private readonly MobileDeviceGate $devices,
    ) {}

    public function handle(string $username, #[\SensitiveParameter] string $password, MobileDeviceInfo $device): MobileLogin
    {
        $deviceSummary = [];

        $user = $this->authenticate->handle(
            $username,
            $password,
            AccountType::ATTENDANT,
            AuthenticateUser::CHANNEL_MOBILE,
            $device->uuid,
            admit: function (User $user) use ($device, &$deviceSummary) {
                $deviceSummary = $this->devices->admit($user, $device);
            },
        );

        return DB::transaction(function () use ($user, $device, $deviceSummary) {
            $this->tokens->revokeUser($user, RevokeReason::RELOGIN, $device->uuid);

            return new MobileLogin($user, $this->tokens->issue($user, $device->uuid, (string) Str::uuid())->pair, $deviceSummary);
        });
    }
}
