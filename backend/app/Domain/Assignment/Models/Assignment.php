<?php

namespace App\Domain\Assignment\Models;

use App\Domain\Assignment\Enums\AssignmentPhase;
use App\Domain\Assignment\Enums\AssignmentStatus;
use App\Domain\ParkingAttendant\Models\ParkingAttendant;
use App\Domain\ParkingLocation\Models\ParkingLocation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Attendant ↔ location for an inclusive date range (WIB calendar days).
 * Written only by the Assignment Actions.
 *
 * @property int $id
 * @property int $attendant_id
 * @property int $location_id
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_until
 * @property AssignmentStatus $status
 * @property int|null $created_by
 * @property string|null $cancel_reason
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class Assignment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_date',
            'effective_until' => 'immutable_date',
            'status' => AssignmentStatus::class,
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

    /**
     * ACTIVE assignments covering the given day (Y-m-d, WIB).
     *
     * @param  Builder<self>  $query
     */
    public function scopeCoveringDate(Builder $query, string $date): void
    {
        $query->where('status', AssignmentStatus::ACTIVE->value)
            ->where('effective_from', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $date));
    }

    public function phaseOn(string $date): AssignmentPhase
    {
        return match (true) {
            $this->status === AssignmentStatus::CANCELLED => AssignmentPhase::CANCELLED,
            $this->effective_from->toDateString() > $date => AssignmentPhase::SCHEDULED,
            $this->effective_until !== null && $this->effective_until->toDateString() < $date => AssignmentPhase::FINISHED,
            default => AssignmentPhase::CURRENT,
        };
    }
}
