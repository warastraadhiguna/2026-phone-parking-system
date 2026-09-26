<?php

namespace App\Domain\Device\Actions;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Device\Enums\DeviceStatus;
use App\Domain\Device\Models\Device;
use App\Domain\Identity\Actions\RevokeMobileSessions;
use App\Domain\Identity\Enums\RevokeReason;
use App\Domain\Identity\Models\User;
use App\Domain\ParkingAttendant\Models\ParkingAttendant;
use App\Support\Errors\RuleViolation;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Revokes a device (also used to reject a pending one) or marks it lost. Final.
 * Every mobile session on that device ends immediately.
 */
final class DeactivateDevice
{
    public function __construct(
        private readonly RevokeMobileSessions $revokeSessions,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(Device $device, DeviceStatus $target, string $reason, User $actor): Device
    {
        if (! $target->isFinal()) {
            throw new InvalidArgumentException('Target status must be REVOKED or LOST.');
        }

        return DB::transaction(function () use ($device, $target, $reason, $actor) {
            $device = Device::query()->lockForUpdate()->findOrFail($device->id);
            $from = $device->status;

            if (! $from->canTransitionTo($target)) {
                throw new RuleViolation('device', "Perangkat berstatus {$from->label()} tidak dapat diubah menjadi {$target->label()}.");
            }

            $device->forceFill([
                'status' => $target,
                'deactivated_at' => now(),
                'deactivated_by' => $actor->id,
                'deactivation_reason' => trim($reason),
            ])->save();

            /** @var ParkingAttendant $attendant */
            $attendant = $device->attendant;
            /** @var User $user */
            $user = $attendant->user;
            $revoked = $this->revokeSessions->handle($user, RevokeReason::DEVICE_DEACTIVATED, $device->device_uuid);

            $this->audit->handle(
                $target === DeviceStatus::LOST ? AuditAction::DEVICE_MARKED_LOST : AuditAction::DEVICE_REVOKED,
                $actor,
                'device',
                $device->id,
                [
                    'from' => $from->value,
                    'attendant_id' => $device->attendant_id,
                    'device_uuid' => $device->device_uuid,
                    'reason' => trim($reason),
                    'revoked_mobile_sessions' => $revoked,
                ],
            );

            return $device;
        });
    }
}
