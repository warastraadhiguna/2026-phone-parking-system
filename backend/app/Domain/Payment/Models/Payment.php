<?php

namespace App\Domain\Payment\Models;

use App\Domain\ParkingTransaction\Models\ParkingTransaction;
use App\Domain\Payment\Enums\PaymentProvider;
use App\Domain\Payment\Enums\PaymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A QRIS payment. Written only by Payment Actions; the database trigger payments_guard()
 * freezes identity and amount and allows only forward status moves (ADR-0006).
 *
 * @property int $id
 * @property string $payment_uuid
 * @property int $transaction_id
 * @property PaymentProvider $provider
 * @property string $provider_order_id
 * @property string|null $provider_reference
 * @property string $payment_method
 * @property int $amount
 * @property PaymentStatus $status
 * @property string|null $qr_string
 * @property string|null $qr_image_url
 * @property CarbonImmutable|null $expired_at
 * @property CarbonImmutable|null $paid_at
 * @property string|null $status_reason
 * @property int $charge_attempts
 * @property CarbonImmutable|null $last_status_check_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read ParkingTransaction $transaction  (transaction_id is NOT NULL)
 */
class Payment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'amount' => 'integer',
            'status' => PaymentStatus::class,
            'expired_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'charge_attempts' => 'integer',
            'last_status_check_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ParkingTransaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(ParkingTransaction::class, 'transaction_id');
    }

    /** @return HasMany<PaymentProviderEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(PaymentProviderEvent::class);
    }

    /** @return HasMany<PaymentAdjustment, $this> */
    public function adjustments(): HasMany
    {
        return $this->hasMany(PaymentAdjustment::class);
    }

    public function refundedAmount(): int
    {
        return (int) $this->adjustments()->sum('amount');
    }
}
