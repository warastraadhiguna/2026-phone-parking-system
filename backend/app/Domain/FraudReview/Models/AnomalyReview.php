<?php

namespace App\Domain\FraudReview\Models;

use App\Domain\FraudReview\Enums\ReviewSeverity;
use App\Domain\FraudReview\Enums\ReviewSource;
use App\Domain\FraudReview\Enums\ReviewStatus;
use App\Domain\Identity\Models\User;
use App\Domain\ParkingAttendant\Models\ParkingAttendant;
use App\Domain\ParkingLocation\Models\ParkingLocation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One signal to review. The facts are frozen; only the single final decision can be added
 * (database trigger).
 *
 * @property int $id
 * @property ReviewSource $source
 * @property string $entity_type
 * @property string $entity_id
 * @property int|null $entity_key
 * @property string|null $reference
 * @property string $code
 * @property ReviewSeverity $severity
 * @property int|null $attendant_id
 * @property int|null $location_id
 * @property int|null $amount
 * @property CarbonImmutable $occurred_at
 * @property ReviewStatus $status
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property CarbonImmutable $created_at
 */
class AnomalyReview extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'source' => ReviewSource::class,
            'severity' => ReviewSeverity::class,
            'status' => ReviewStatus::class,
            'entity_key' => 'integer',
            'amount' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
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

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
