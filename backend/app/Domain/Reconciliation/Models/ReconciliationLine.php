<?php

namespace App\Domain\Reconciliation\Models;

use App\Domain\Reconciliation\Enums\LineDimension;
use Illuminate\Database\Eloquent\Model;

/**
 * One attendant or location in a run. Cash ledger columns are null for locations (cash is held
 * by attendants, not by places).
 *
 * @property int $id
 * @property int $run_id
 * @property LineDimension $dimension
 * @property int $dimension_id
 * @property string $label
 * @property int $transaction_count
 * @property int $expected_cash
 * @property int $voided_cash
 * @property int|null $ledger_cash_in
 * @property int|null $ledger_reversals
 * @property int|null $cash_deposited
 * @property int|null $cash_outstanding
 * @property int $qris_expected
 * @property int $qris_paid
 * @property int $qris_refunded
 * @property int $qris_difference
 * @property int $qris_open
 * @property int $total_revenue
 */
class ReconciliationLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [...array_fill_keys(ReconciliationRun::METRICS, 'integer'), 'dimension' => LineDimension::class, 'dimension_id' => 'integer'];
    }
}
