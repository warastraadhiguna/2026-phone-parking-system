<?php

namespace App\Domain\Shift\Actions;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Device\Models\Device;
use App\Domain\Identity\Models\User;
use App\Domain\ParkingAttendant\Models\ParkingAttendant;
use App\Domain\ParkingLocation\Enums\GeofenceResult;
use App\Domain\ParkingLocation\Models\ParkingLocation;
use App\Domain\ParkingLocation\Services\Geofence;
use App\Domain\Shift\Data\ShiftEndData;
use App\Domain\Shift\Data\ShiftOutcome;
use App\Domain\Shift\Enums\ShiftFlag;
use App\Domain\Shift\Enums\ShiftStatus;
use App\Domain\Shift\Models\Shift;
use App\Domain\SystemConfiguration\Enums\SettingKey;
use App\Domain\SystemConfiguration\Services\Settings;
use App\Support\Errors\ApiException;
use App\Support\Errors\ErrorCode;
use App\Support\Errors\RuleViolation;
use App\Support\Idempotency\PayloadHash;
use Illuminate\Support\Facades\DB;

/**
 * Ends the attendant's shift. Idempotent: repeating the same end returns the closed shift.
 * A shift already force-closed by a supervisor is returned as-is (the app learns its status).
 */
final class EndShift
{
    public function __construct(
        private readonly Geofence $geofence,
        private readonly Settings $settings,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $user, ParkingAttendant $attendant, Device $device, ShiftEndData $data): ShiftOutcome
    {
        $hash = PayloadHash::of($data->fingerprint());

        return DB::transaction(function () use ($user, $attendant, $device, $data, $hash) {
            $shift = Shift::query()->where('shift_uuid', $data->shiftUuid)->lockForUpdate()->first();

            if ($shift === null || $shift->attendant_id !== $attendant->id) {
                throw new ApiException(ErrorCode::NOT_FOUND, 'Shift tidak ditemukan.');
            }

            if ($shift->status === ShiftStatus::FORCED_CLOSED) {
                return new ShiftOutcome($shift, created: false);
            }
            if ($shift->status === ShiftStatus::CLOSED) {
                if ($shift->end_payload_hash !== null && hash_equals($shift->end_payload_hash, $hash)) {
                    return new ShiftOutcome($shift, created: false);
                }
                throw new ApiException(ErrorCode::SYNC_CONFLICT, 'Shift sudah ditutup dengan data berbeda.', ['shift_uuid' => $shift->shift_uuid]);
            }

            if ($data->endedAtDevice->lessThan($shift->started_at_device)) {
                throw new RuleViolation('ended_at_device', 'Waktu selesai tidak boleh sebelum waktu mulai shift.', ErrorCode::VALIDATION_FAILED);
            }

            /** @var ParkingLocation $location */
            $location = $shift->location;
            $check = $this->geofence->check($location, $data->gps, $this->settings->int(SettingKey::GPS_MAX_ACCURACY_M));

            $flags = [];
            if ($check->result === GeofenceResult::OUTSIDE) {
                $flags[] = ShiftFlag::OUTSIDE_GEOFENCE_END;
            }
            if ($data->gps->mockLocation) {
                $flags[] = ShiftFlag::MOCK_LOCATION;
            }

            $shift->forceFill([
                'status' => ShiftStatus::CLOSED,
                'ended_at_device' => $data->endedAtDevice,
                'ended_at_server' => now(),
                'end_latitude' => $data->gps->latitude,
                'end_longitude' => $data->gps->longitude,
                'end_gps_accuracy_m' => $data->gps->accuracyM,
                'end_mock_location' => $data->gps->mockLocation,
                'end_geofence_result' => $check->result,
                'end_distance_m' => $check->distanceM,
                'end_payload_hash' => $hash,
            ])->withFlags($flags)->save();

            $this->audit->handle(AuditAction::END_SHIFT, $user, 'shift', $shift->shift_uuid, [
                'geofence' => $check->result->value,
                'distance_m' => $check->distanceM,
                'duration_minutes' => (int) $shift->started_at_device->diffInMinutes($data->endedAtDevice),
                'flags' => $shift->review_flags,
            ], deviceUuid: $device->device_uuid);

            return new ShiftOutcome($shift, created: true);
        });
    }
}
