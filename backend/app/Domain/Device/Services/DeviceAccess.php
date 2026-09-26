<?php

namespace App\Domain\Device\Services;

use App\Domain\Device\Enums\DeviceStatus;
use App\Domain\Device\Models\Device;
use App\Domain\Identity\Models\User;
use App\Domain\ParkingAttendant\Models\ParkingAttendant;
use App\Support\Errors\ApiException;
use App\Support\Errors\ErrorCode;

/**
 * Read-side checks other modules and the HTTP layer use before operational actions
 * (start shift, create transaction, sync).
 */
final class DeviceAccess
{
    public function find(User $user, ?string $deviceUuid): ?Device
    {
        if ($deviceUuid === null) {
            return null;
        }

        return Device::query()
            ->where('device_uuid', $deviceUuid)
            ->whereHas('attendant', fn ($q) => $q->where('user_id', $user->id))
            ->first();
    }

    /**
     * The device must be ACTIVE and belong to an operational attendant.
     *
     * @throws ApiException DEVICE_NOT_ALLOWED
     */
    public function assertOperational(User $user, ?string $deviceUuid): Device
    {
        $device = $this->find($user, $deviceUuid);

        if ($device === null || $device->status !== DeviceStatus::ACTIVE) {
            throw new ApiException(ErrorCode::DEVICE_NOT_ALLOWED, match ($device?->status) {
                DeviceStatus::PENDING_APPROVAL => 'Perangkat menunggu persetujuan admin.',
                null => 'Perangkat tidak terdaftar.',
                default => 'Perangkat ini sudah dicabut.',
            });
        }

        /** @var ParkingAttendant $attendant */
        $attendant = $device->attendant;
        if (! $attendant->isOperational()) {
            throw new ApiException(ErrorCode::ACCOUNT_DISABLED, 'Juru parkir tidak aktif.');
        }

        return $device;
    }
}
