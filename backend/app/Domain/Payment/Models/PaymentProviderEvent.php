<?php

namespace App\Domain\Payment\Models;

use App\Domain\Payment\Enums\ProviderEventOutcome;
use App\Domain\Payment\Enums\ProviderEventSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only record of a verified provider answer and its outcome.
 *
 * @property int $id
 * @property int|null $payment_id
 * @property string $provider
 * @property ProviderEventSource $source
 * @property string|null $event_key
 * @property string|null $provider_order_id
 * @property string $provider_status
 * @property int|null $reported_amount
 * @property ProviderEventOutcome $outcome
 * @property string|null $payment_status_before
 * @property string|null $payment_status_after
 * @property array<string, mixed> $payload
 * @property string|null $request_id
 * @property CarbonImmutable $created_at
 */
class PaymentProviderEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'source' => ProviderEventSource::class,
            'outcome' => ProviderEventOutcome::class,
            'reported_amount' => 'integer',
            'payload' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
