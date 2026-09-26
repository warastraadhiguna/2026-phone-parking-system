<?php

namespace App\Domain\CashLedger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Derived, rebuildable balance per attendant (ADR-0007). NOT the source of truth.
 *
 * @property int $attendant_id
 * @property int $balance
 * @property int|null $last_entry_id
 * @property CarbonImmutable $updated_at
 */
class AttendantCashBalance extends Model
{
    public const CREATED_AT = null;

    protected $primaryKey = 'attendant_id';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'balance' => 'integer',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
