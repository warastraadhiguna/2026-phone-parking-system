<?php

namespace App\Domain\CashLedger\Actions;

use App\Domain\CashLedger\Enums\LedgerEntryType;
use App\Domain\CashLedger\Internal\LedgerWriter;
use App\Domain\CashLedger\Models\CashLedgerEntry;
use App\Domain\CashSettlement\Models\CashSettlement;
use App\Domain\Identity\Models\User;
use App\Support\Errors\RuleViolation;

/**
 * Master doc §11–§13: a verified deposit takes the counted cash out of the attendant's balance.
 * Never more than the attendant holds (also a DB CHECK on balance_after).
 */
final class RecordSettlementOut
{
    public function __construct(private readonly LedgerWriter $writer) {}

    /** Call inside the transaction that verifies the settlement. */
    public function handle(CashSettlement $settlement, int $amount, User $verifier): CashLedgerEntry
    {
        $balance = $this->writer->lockedBalance($settlement->attendant_id);
        if ($amount < 1 || $amount > $balance) {
            throw new RuleViolation('verified_amount', "Jumlah diterima harus antara 1 dan saldo kas juru parkir ({$balance}).");
        }

        return $this->writer->append($settlement->attendant_id, LedgerEntryType::SETTLEMENT_OUT, -$amount, [
            'shift_id' => $settlement->shift_id,
            'settlement_id' => $settlement->id,
            'description' => $settlement->settlement_number,
            'created_by' => $verifier->id,
        ]);
    }
}
