<?php

namespace App\Domain\CashSettlement\Models;

use App\Domain\CashLedger\Models\CashLedgerEntry;
use App\Domain\CashSettlement\Enums\SettlementStatus;
use App\Domain\Identity\Models\User;
use App\Domain\ParkingAttendant\Models\ParkingAttendant;
use App\Domain\Shift\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A cash deposit by an attendant (master doc §13). Written only by CashSettlement Actions;
 * frozen once decided (database trigger).
 *
 * @property int $id
 * @property string $settlement_uuid
 * @property string $settlement_number
 * @property int $attendant_id
 * @property int|null $shift_id
 * @property int|null $device_id
 * @property int $amount
 * @property int $balance_at_submission
 * @property string|null $notes
 * @property string|null $proof_path
 * @property string|null $proof_sha256
 * @property SettlementStatus $status
 * @property int $submitted_by
 * @property CarbonImmutable $submitted_at
 * @property int|null $verified_amount
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property string $payload_hash
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class CashSettlement extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'balance_at_submission' => 'integer',
            'verified_amount' => 'integer',
            'status' => SettlementStatus::class,
            'submitted_at' => 'immutable_datetime',
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

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return HasOne<CashLedgerEntry, $this> */
    public function ledgerEntry(): HasOne
    {
        return $this->hasOne(CashLedgerEntry::class, 'settlement_id');
    }
}
