<?php

namespace App\Domain\ParkingLocation\Models;

use App\Domain\Assignment\Models\Assignment;
use App\Domain\ParkingLocation\Enums\LocationStatus;
use App\Domain\ParkingLocation\Enums\LocationType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A parking location. Written only by the ParkingLocation Actions.
 *
 * @property int $id
 * @property string $location_code
 * @property string $name
 * @property string $address
 * @property string $latitude
 * @property string $longitude
 * @property int $geofence_radius_m
 * @property LocationType $location_type
 * @property LocationStatus $status
 * @property int $motorcycle_capacity
 * @property int $car_capacity
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class ParkingLocation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'geofence_radius_m' => 'integer',
            'location_type' => LocationType::class,
            'status' => LocationStatus::class,
            'motorcycle_capacity' => 'integer',
            'car_capacity' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<Assignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class, 'location_id');
    }

    public function isOperational(): bool
    {
        return $this->status->isOperational();
    }
}
