<?php

namespace App\Domain\ParkingTransaction\Models;

use App\Domain\Identity\Models\User;
use App\Domain\ParkingTransaction\Enums\VoidChannel;
use App\Domain\ParkingTransaction\Enums\VoidRequestStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $transaction_id
 * @property int $requested_by
 * @property VoidChannel $channel
 * @property string $reason
 * @property VoidRequestStatus $status
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class VoidRequest extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'channel' => VoidChannel::class,
            'status' => VoidRequestStatus::class,
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ParkingTransaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(ParkingTransaction::class, 'transaction_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
