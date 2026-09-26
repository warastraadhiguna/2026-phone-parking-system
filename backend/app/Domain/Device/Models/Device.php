<?php

namespace App\Domain\Device\Models;

use App\Domain\Device\Enums\DeviceStatus;
use App\Domain\Identity\Models\User;
use App\Domain\ParkingAttendant\Models\ParkingAttendant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An Android installation registered to an attendant. Written only by the Device Actions.
 *
 * @property int $id
 * @property string $device_uuid
 * @property int $attendant_id
 * @property string|null $device_model
 * @property string|null $android_version
 * @property string|null $app_version
 * @property DeviceStatus $status
 * @property CarbonImmutable $registered_at
 * @property CarbonImmutable|null $approved_at
 * @property int|null $approved_by
 * @property CarbonImmutable|null $deactivated_at
 * @property int|null $deactivated_by
 * @property string|null $deactivation_reason
 * @property CarbonImmutable|null $last_seen_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class Device extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => DeviceStatus::class,
            'registered_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'deactivated_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ParkingAttendant, $this> */
    public function attendant(): BelongsTo
    {
        return $this->belongsTo(ParkingAttendant::class, 'attendant_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function deactivator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivated_by');
    }
}
