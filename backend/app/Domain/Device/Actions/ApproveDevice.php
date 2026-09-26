<?php

namespace App\Domain\Device\Actions;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Device\Enums\DeviceStatus;
use App\Domain\Device\Models\Device;
use App\Domain\Identity\Models\User;
use App\Support\Errors\RuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * PENDING_APPROVAL → ACTIVE. The attendant must not already have an ACTIVE device: the old one
 * has to be revoked or marked lost first, as a separate, explicit action (ADR-0005).
 */
final class ApproveDevice
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(Device $device, User $actor): Device
    {
        return DB::transaction(function () use ($device, $actor) {
            $device = Device::query()->lockForUpdate()->findOrFail($device->id);

            if (! $device->status->canTransitionTo(DeviceStatus::ACTIVE)) {
                throw new RuleViolation('device', "Perangkat berstatus {$device->status->label()} tidak dapat disetujui.");
            }

            $hasActive = Device::query()
                ->where('attendant_id', $device->attendant_id)
                ->where('status', DeviceStatus::ACTIVE->value)
                ->lockForUpdate()
                ->exists();
            if ($hasActive) {
                throw new RuleViolation('device', 'Juru parkir ini sudah memiliki perangkat aktif. Cabut perangkat lama terlebih dahulu.');
            }

            $device->forceFill([
                'status' => DeviceStatus::ACTIVE,
                'approved_at' => now(),
                'approved_by' => $actor->id,
            ])->save();

            $this->audit->handle(AuditAction::DEVICE_APPROVED, $actor, 'device', $device->id, [
                'attendant_id' => $device->attendant_id,
                'device_uuid' => $device->device_uuid,
            ]);

            return $device;
        });
    }
}
