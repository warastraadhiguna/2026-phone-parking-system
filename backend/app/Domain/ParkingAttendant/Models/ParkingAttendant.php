<?php

namespace App\Domain\ParkingAttendant\Models;

use App\Domain\Assignment\Models\Assignment;
use App\Domain\Device\Models\Device;
use App\Domain\Identity\Models\User;
use App\Domain\ParkingAttendant\Enums\AttendantStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A registered juru parkir. Written only by the ParkingAttendant Actions.
 *
 * identity_number (NIK) is personal data: never log it, mask it in lists.
 *
 * @property int $id
 * @property string $attendant_code
 * @property int $user_id
 * @property string $name
 * @property string $identity_number
 * @property string $phone
 * @property string|null $photo_path
 * @property AttendantStatus $status
 * @property CarbonImmutable $registered_at
 * @property CarbonImmutable|null $expired_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class ParkingAttendant extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['identity_number'];

    protected function casts(): array
    {
        return [
            'status' => AttendantStatus::class,
            'registered_at' => 'immutable_date',
            'expired_at' => 'immutable_date',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Device, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class, 'attendant_id');
    }

    /** @return HasMany<Assignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class, 'attendant_id');
    }

    public function isOperational(): bool
    {
        return $this->status->isOperational();
    }

    public function maskedIdentityNumber(): string
    {
        return str_repeat('•', 12).substr($this->identity_number, -4);
    }
}
