<?php

namespace App\Domain\Identity\Contracts;

use App\Domain\Identity\Data\MobileDeviceInfo;
use App\Domain\Identity\Models\User;
use App\Support\Errors\ApiException;

/**
 * Lets the Device module decide whether an attendant may use a device, without Identity
 * depending on it. Implemented by App\Domain\Device\Services\DeviceGatekeeper.
 */
interface MobileDeviceGate
{
    /**
     * Called at login after the credentials were verified, before tokens are issued.
     *
     * @return array<string, mixed> device summary returned to the app under "device"
     *
     * @throws ApiException to refuse the login
     */
    public function admit(User $user, MobileDeviceInfo $device): array;

    /**
     * Called on every token refresh.
     *
     * @throws ApiException to refuse; the session is then revoked
     */
    public function assertStillAdmitted(User $user, string $deviceUuid): void;
}
