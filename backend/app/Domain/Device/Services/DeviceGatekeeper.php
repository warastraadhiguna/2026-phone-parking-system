<?php

namespace App\Domain\Device\Services;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Device\Enums\DeviceStatus;
use App\Domain\Device\Models\Device;
use App\Domain\Identity\Contracts\MobileDeviceGate;
use App\Domain\Identity\Data\MobileDeviceInfo;
use App\Domain\Identity\Models\User;
use App\Domain\ParkingAttendant\Models\ParkingAttendant;
use App\Support\Errors\ApiException;
use App\Support\Errors\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Device binding for attendant logins (ADR-0005).
 *
 * - Unknown device UUID → registered as PENDING_APPROVAL for this attendant; login succeeds so the
 *   app can show "waiting for approval", but operational endpoints stay closed (EnsureActiveDevice).
 * - Device registered to another attendant, or REVOKED/LOST → refused (DEVICE_NOT_ALLOWED).
 */
final class DeviceGatekeeper implements MobileDeviceGate
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function admit(User $user, MobileDeviceInfo $device): array
    {
        return DB::transaction(function () use ($user, $device) {
            $attendant = $this->attendantOf($user);

            /** @var Device|null $record */
            $record = Device::query()->where('device_uuid', $device->uuid)->lockForUpdate()->first();

            if ($record === null) {
                $record = Device::create([
                    'device_uuid' => $device->uuid,
                    'attendant_id' => $attendant->id,
                    'device_model' => $device->model,
                    'android_version' => $device->androidVersion,
                    'app_version' => $device->appVersion,
                    'status' => DeviceStatus::PENDING_APPROVAL,
                    'registered_at' => now(),
                    'last_seen_at' => now(),
                ]);

                $this->audit->handle(AuditAction::DEVICE_REGISTERED, $user, 'device', $record->id, [
                    'attendant_id' => $attendant->id,
                    'device_model' => $device->model,
                    'app_version' => $device->appVersion,
                ], deviceUuid: $device->uuid);
            } else {
                $this->assertUsable($record, $attendant);
                $record->forceFill(array_filter([
                    'device_model' => $device->model,
                    'android_version' => $device->androidVersion,
                    'app_version' => $device->appVersion,
                ], fn ($v) => $v !== null) + ['last_seen_at' => now()])->save();
            }

            return self::summary($record);
        });
    }

    public function assertStillAdmitted(User $user, string $deviceUuid): void
    {
        $attendant = $this->attendantOf($user);
        $record = Device::query()->where('device_uuid', $deviceUuid)->first();

        if ($record === null) {
            throw new ApiException(ErrorCode::DEVICE_NOT_ALLOWED, 'Perangkat tidak terdaftar.');
        }

        $this->assertUsable($record, $attendant);
        $record->forceFill(['last_seen_at' => now()])->save();
    }

    /** @return array{uuid: string, status: string, status_label: string} */
    public static function summary(Device $device): array
    {
        return [
            'uuid' => $device->device_uuid,
            'status' => $device->status->value,
            'status_label' => $device->status->label(),
        ];
    }

    private function attendantOf(User $user): ParkingAttendant
    {
        $attendant = ParkingAttendant::query()->where('user_id', $user->id)->first();

        if ($attendant === null) {
            throw new ApiException(ErrorCode::ACCOUNT_DISABLED, 'Akun ini belum terdaftar sebagai juru parkir.');
        }

        return $attendant;
    }

    private function assertUsable(Device $record, ParkingAttendant $attendant): void
    {
        if ($record->attendant_id !== $attendant->id) {
            throw new ApiException(ErrorCode::DEVICE_NOT_ALLOWED, 'Perangkat ini terdaftar untuk juru parkir lain.');
        }

        if ($record->status->isFinal()) {
            throw new ApiException(ErrorCode::DEVICE_NOT_ALLOWED, 'Perangkat ini sudah dicabut. Hubungi admin untuk mendaftarkan perangkat baru.');
        }
    }
}
