<?php

namespace App\Domain\Shift\Actions;

use App\Domain\Assignment\Services\AssignmentLookup;
use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Device\Models\Device;
use App\Domain\Identity\Models\User;
use App\Domain\ParkingAttendant\Models\ParkingAttendant;
use App\Domain\ParkingLocation\Enums\GeofenceResult;
use App\Domain\ParkingLocation\Models\ParkingLocation;
use App\Domain\ParkingLocation\Services\Geofence;
use App\Domain\Shift\Data\ShiftOutcome;
use App\Domain\Shift\Data\ShiftStartData;
use App\Domain\Shift\Enums\ShiftFlag;
use App\Domain\Shift\Enums\ShiftStatus;
use App\Domain\Shift\Models\Shift;
use App\Domain\SystemConfiguration\Enums\SettingKey;
use App\Domain\SystemConfiguration\Services\DeviceTimePolicy;
use App\Domain\SystemConfiguration\Services\Settings;
use App\Support\Errors\ApiException;
use App\Support\Errors\ErrorCode;
use App\Support\Idempotency\PayloadHash;
use App\Support\Time\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Starts a shift created by the device (ADR-0008). Idempotent on shift_uuid:
 * same UUID + same payload → the existing shift; same UUID + different payload → SYNC_CONFLICT.
 *
 * Online starts are validated strictly (assignment, location). An offline-created shift already
 * happened in the field, so rule violations become review flags instead of a rejection. The one
 * hard rule in both cases: one OPEN shift per attendant.
 */
final class StartShift
{
    public function __construct(
        private readonly AssignmentLookup $assignments,
        private readonly Geofence $geofence,
        private readonly Settings $settings,
        private readonly DeviceTimePolicy $time,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $user, ParkingAttendant $attendant, Device $device, ShiftStartData $data): ShiftOutcome
    {
        $hash = PayloadHash::of($data->fingerprint());

        try {
            return DB::transaction(function () use ($user, $attendant, $device, $data, $hash) {
                $existing = Shift::query()->where('shift_uuid', $data->shiftUuid)->lockForUpdate()->first();
                if ($existing !== null) {
                    if ($existing->attendant_id !== $attendant->id || ! hash_equals($existing->start_payload_hash, $hash)) {
                        throw new ApiException(ErrorCode::SYNC_CONFLICT, 'Shift dengan UUID ini sudah ada dengan data berbeda.', ['shift_uuid' => $data->shiftUuid]);
                    }

                    return new ShiftOutcome($existing, created: false);
                }

                $open = Shift::query()->where('attendant_id', $attendant->id)->where('status', ShiftStatus::OPEN->value)->first();
                if ($open !== null) {
                    throw new ApiException(ErrorCode::SHIFT_ALREADY_OPEN, 'Masih ada shift yang belum ditutup.', ['open_shift_uuid' => $open->shift_uuid]);
                }

                $location = ParkingLocation::query()->find($data->locationId)
                    ?? throw new ApiException(ErrorCode::LOCATION_NOT_ALLOWED, 'Lokasi tidak ditemukan.');

                $now = CarbonImmutable::now();
                $flags = [];

                // Offline: the day the shift really started; online: today.
                $day = ($data->offlineCreated ? $data->startedAtDevice : $now)->setTimezone(BusinessTime::timezone())->toDateString();
                $assignment = $this->assignments->currentFor($attendant, $day);

                foreach ($this->violations($assignment?->location_id, $location, $device, $data) as $flag => $message) {
                    if (! $data->offlineCreated) {
                        throw new ApiException(ErrorCode::LOCATION_NOT_ALLOWED, $message);
                    }
                    $flags[] = ShiftFlag::from($flag);
                }

                $flags = [...$flags, ...$this->timingFlags($data, $now)];

                $check = $this->geofence->check($location, $data->gps, $this->settings->int(SettingKey::GPS_MAX_ACCURACY_M));
                if ($check->result === GeofenceResult::OUTSIDE) {
                    $flags[] = ShiftFlag::OUTSIDE_GEOFENCE_START;
                }
                if ($data->gps->mockLocation) {
                    $flags[] = ShiftFlag::MOCK_LOCATION;
                }

                $shift = (new Shift([
                    'shift_uuid' => $data->shiftUuid,
                    'attendant_id' => $attendant->id,
                    'location_id' => $location->id,
                    'device_id' => $device->id,
                    'assignment_id' => $assignment?->location_id === $location->id ? $assignment->id : null,
                    'status' => ShiftStatus::OPEN,
                    'offline_created' => $data->offlineCreated,
                    'started_at_device' => $data->startedAtDevice,
                    'started_at_server' => $now,
                    'start_latitude' => $data->gps->latitude,
                    'start_longitude' => $data->gps->longitude,
                    'start_gps_accuracy_m' => $data->gps->accuracyM,
                    'start_mock_location' => $data->gps->mockLocation,
                    'start_geofence_result' => $check->result,
                    'start_distance_m' => $check->distanceM,
                    'review_flags' => [],
                    'start_payload_hash' => $hash,
                ]))->withFlags($flags);
                $shift->save();

                $this->audit->handle(AuditAction::START_SHIFT, $user, 'shift', $shift->shift_uuid, [
                    'location_id' => $location->id,
                    'offline_created' => $data->offlineCreated,
                    'geofence' => $check->result->value,
                    'distance_m' => $check->distanceM,
                    'flags' => $shift->review_flags,
                ], deviceUuid: $device->device_uuid);

                return new ShiftOutcome($shift, created: true);
            });
        } catch (QueryException $e) {
            // Two concurrent starts: the partial unique index is the final arbiter.
            if ($e->getCode() === '23505') {
                throw new ApiException(ErrorCode::SHIFT_ALREADY_OPEN, 'Masih ada shift yang belum ditutup.');
            }
            throw $e;
        }
    }

    /** @return array<string, string> flag value => message */
    private function violations(?int $assignedLocationId, ParkingLocation $location, Device $device, ShiftStartData $data): array
    {
        $violations = [];

        if ($assignedLocationId === null) {
            $violations[ShiftFlag::NO_ASSIGNMENT->value] = 'Tidak ada penugasan untuk hari ini.';
        } elseif ($assignedLocationId !== $location->id) {
            $violations[ShiftFlag::LOCATION_MISMATCH->value] = 'Lokasi ini bukan lokasi penugasan Anda hari ini.';
        }

        if (! $location->isOperational()) {
            $violations[ShiftFlag::LOCATION_INACTIVE->value] = 'Lokasi tidak aktif.';
        }

        if ($data->offlineCreated && ($device->approved_at === null || $device->approved_at->greaterThan($data->startedAtDevice))) {
            $violations[ShiftFlag::DEVICE_NOT_APPROVED_AT_START->value] = 'Perangkat belum disetujui saat shift dimulai.';
        }

        return $violations;
    }

    /** @return list<ShiftFlag> */
    private function timingFlags(ShiftStartData $data, CarbonImmutable $now): array
    {
        $timing = $this->time->assess($data->startedAtDevice, $data->offlineCreated, $now);

        return array_values(array_filter([
            $timing['clock_skew'] ? ShiftFlag::CLOCK_SKEW : null,
            $timing['stale'] ? ShiftFlag::STALE_OFFLINE : null,
        ]));
    }
}
