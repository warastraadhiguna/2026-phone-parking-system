<?php

namespace App\Domain\CashLedger\Models;

use App\Domain\CashLedger\Enums\LedgerEntryType;
use App\Domain\ParkingAttendant\Models\ParkingAttendant;
use App\Domain\ParkingTransaction\Models\ParkingTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One cash movement. The financial source of truth for cash (ADR-0007): append-only here and in
 * the database. Created only through App\Domain\CashLedger\Internal\LedgerWriter.
 *
 * @property int $id
 * @property int $attendant_id
 * @property int|null $shift_id
 * @property int|null $transaction_id
 * @property int|null $settlement_id
 * @property int|null $reverses_entry_id
 * @property LedgerEntryType $type
 * @property int $amount
 * @property int $balance_after
 * @property string|null $description
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 */
class CashLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => LedgerEntryType::class,
            'amount' => 'integer',
            'balance_after' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Cash ledger entries are append-only.'));
        static::deleting(fn () => throw new LogicException('Cash ledger entries are append-only.'));
    }

    /** @return BelongsTo<ParkingAttendant, $this> */
    public function attendant(): BelongsTo
    {
        return $this->belongsTo(ParkingAttendant::class, 'attendant_id');
    }

    /** @return BelongsTo<ParkingTransaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(ParkingTransaction::class, 'transaction_id');
    }
}
