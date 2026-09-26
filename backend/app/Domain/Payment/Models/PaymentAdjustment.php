<?php

namespace App\Domain\Payment\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Payment\Enums\AdjustmentType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A manual refund of a PAID payment (ADR-0006, Q3). Append-only.
 *
 * @property int $id
 * @property string $adjustment_uuid
 * @property int $payment_id
 * @property AdjustmentType $type
 * @property int $amount
 * @property string $reason
 * @property CarbonImmutable $refunded_at
 * @property int $recorded_by
 * @property CarbonImmutable $created_at
 */
class PaymentAdjustment extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => AdjustmentType::class,
            'amount' => 'integer',
            'refunded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
