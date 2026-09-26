<?php

namespace App\Domain\Reconciliation\Models;

use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An immutable reconciliation snapshot for one business date (append-only).
 *
 * @property int $id
 * @property string $run_uuid
 * @property CarbonImmutable $business_date
 * @property CarbonImmutable $window_start
 * @property CarbonImmutable $window_end
 * @property int|null $run_by
 * @property int $transaction_count
 * @property int $expected_cash
 * @property int $voided_cash
 * @property int $ledger_cash_in
 * @property int $ledger_reversals
 * @property int $cash_deposited
 * @property int $cash_outstanding
 * @property int $qris_expected
 * @property int $qris_paid
 * @property int $qris_refunded
 * @property int $qris_difference
 * @property int $qris_open
 * @property int $total_revenue
 * @property int $mismatch_count
 * @property int $error_count
 * @property CarbonImmutable $created_at
 */
class ReconciliationRun extends Model
{
    public const UPDATED_AT = null;

    public const METRICS = [
        'transaction_count', 'expected_cash', 'voided_cash', 'ledger_cash_in', 'ledger_reversals', 'cash_deposited',
        'cash_outstanding', 'qris_expected', 'qris_paid', 'qris_refunded', 'qris_difference', 'qris_open', 'total_revenue',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            ...array_fill_keys(self::METRICS, 'integer'),
            'business_date' => 'immutable_date',
            'window_start' => 'immutable_datetime',
            'window_end' => 'immutable_datetime',
            'mismatch_count' => 'integer',
            'error_count' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<ReconciliationLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ReconciliationLine::class, 'run_id');
    }

    /** @return HasMany<ReconciliationMismatch, $this> */
    public function mismatches(): HasMany
    {
        return $this->hasMany(ReconciliationMismatch::class, 'run_id');
    }

    /** @return BelongsTo<User, $this> */
    public function runner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by');
    }
}
