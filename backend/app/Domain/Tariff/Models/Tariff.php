<?php

namespace App\Domain\Tariff\Models;

use App\Domain\Identity\Models\User;
use App\Domain\ParkingLocation\Enums\LocationType;
use App\Domain\ParkingLocation\Models\ParkingLocation;
use App\Domain\Tariff\Enums\TariffStatus;
use App\Domain\Tariff\Enums\VehicleType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tariff version. Amount is integer rupiah. Written only by the Tariff Actions;
 * approved rows are frozen by a database trigger.
 *
 * @property int $id
 * @property VehicleType $vehicle_type
 * @property LocationType $location_type
 * @property int|null $location_id
 * @property int $amount
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_until
 * @property string $regulation_reference
 * @property TariffStatus $status
 * @property int $created_by
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property string|null $rejection_reason
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class Tariff extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'vehicle_type' => VehicleType::class,
            'location_type' => LocationType::class,
            'amount' => 'integer',
            'effective_from' => 'immutable_datetime',
            'effective_until' => 'immutable_datetime',
            'status' => TariffStatus::class,
            'approved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ParkingLocation, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(ParkingLocation::class, 'location_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isInEffectAt(CarbonImmutable $at): bool
    {
        return $this->status === TariffStatus::APPROVED
            && $this->effective_from->lessThanOrEqualTo($at)
            && ($this->effective_until === null || $this->effective_until->greaterThan($at));
    }
}
