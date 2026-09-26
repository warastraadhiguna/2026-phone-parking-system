<?php

namespace App\Domain\Shift\Models;

use App\Domain\Assignment\Models\Assignment;
use App\Domain\Device\Models\Device;
use App\Domain\ParkingAttendant\Models\ParkingAttendant;
use App\Domain\ParkingLocation\Enums\GeofenceResult;
use App\Domain\ParkingLocation\Models\ParkingLocation;
use App\Domain\Shift\Enums\ShiftFlag;
use App\Domain\Shift\Enums\ShiftStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A shift. Written only by the Shift Actions.
 *
 * @property int $id
 * @property string $shift_uuid
 * @property int $attendant_id
 * @property int $location_id
 * @property int $device_id
 * @property int|null $assignment_id
 * @property ShiftStatus $status
 * @property bool $offline_created
 * @property CarbonImmutable $started_at_device
 * @property CarbonImmutable $started_at_server
 * @property string|null $start_latitude
 * @property string|null $start_longitude
 * @property string|null $start_gps_accuracy_m
 * @property bool $start_mock_location
 * @property GeofenceResult $start_geofence_result
 * @property int|null $start_distance_m
 * @property CarbonImmutable|null $ended_at_device
 * @property CarbonImmutable|null $ended_at_server
 * @property string|null $end_latitude
 * @property string|null $end_longitude
 * @property string|null $end_gps_accuracy_m
 * @property bool $end_mock_location
 * @property GeofenceResult|null $end_geofence_result
 * @property int|null $end_distance_m
 * @property int|null $force_closed_by
 * @property string|null $close_reason
 * @property list<string> $review_flags
 * @property string $start_payload_hash
 * @property string|null $end_payload_hash
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class Shift extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ShiftStatus::class,
            'offline_created' => 'boolean',
            'started_at_device' => 'immutable_datetime',
            'started_at_server' => 'immutable_datetime',
            'ended_at_device' => 'immutable_datetime',
            'ended_at_server' => 'immutable_datetime',
            'start_latitude' => 'decimal:7',
            'start_longitude' => 'decimal:7',
            'start_gps_accuracy_m' => 'decimal:2',
            'end_latitude' => 'decimal:7',
            'end_longitude' => 'decimal:7',
            'end_gps_accuracy_m' => 'decimal:2',
            'start_mock_location' => 'boolean',
            'end_mock_location' => 'boolean',
            'start_geofence_result' => GeofenceResult::class,
            'end_geofence_result' => GeofenceResult::class,
            'review_flags' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ParkingAttendant, $this> */
    public function attendant(): BelongsTo
    {
        return $this->belongsTo(ParkingAttendant::class, 'attendant_id');
    }

    /** @return BelongsTo<ParkingLocation, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(ParkingLocation::class, 'location_id');
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    /** @return BelongsTo<Assignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'assignment_id');
    }

    public function isOpen(): bool
    {
        return $this->status === ShiftStatus::OPEN;
    }

    public function hasFlag(ShiftFlag $flag): bool
    {
        return in_array($flag->value, $this->review_flags, true);
    }

    /** @param  list<ShiftFlag>  $flags */
    public function withFlags(array $flags): self
    {
        $this->review_flags = array_values(array_unique([...$this->review_flags, ...array_map(fn (ShiftFlag $f) => $f->value, $flags)]));

        return $this;
    }
}
