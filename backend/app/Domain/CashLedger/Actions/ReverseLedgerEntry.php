<?php

namespace App\Domain\CashLedger\Actions;

use App\Domain\CashLedger\Enums\LedgerEntryType;
use App\Domain\CashLedger\Internal\LedgerWriter;
use App\Domain\CashLedger\Models\CashLedgerEntry;
use App\Domain\Identity\Models\User;
use App\Support\Errors\RuleViolation;

/**
 * Writes the exact counter-entry of an earlier entry (§46 rule 10). The original stays untouched;
 * an entry can be reversed once (unique index), and a reversal cannot itself be reversed.
 */
final class ReverseLedgerEntry
{
    public function __construct(private readonly LedgerWriter $writer) {}

    /** Call inside the caller's transaction. */
    public function handle(CashLedgerEntry $original, string $description, ?User $actor): CashLedgerEntry
    {
        if ($original->type === LedgerEntryType::REVERSAL) {
            throw new RuleViolation('ledger', 'Entri pembatalan tidak dapat dibatalkan lagi.');
        }
        if (CashLedgerEntry::query()->where('reverses_entry_id', $original->id)->exists()) {
            throw new RuleViolation('ledger', 'Entri ini sudah pernah dibatalkan.');
        }

        return $this->writer->append($original->attendant_id, LedgerEntryType::REVERSAL, -$original->amount, [
            'shift_id' => $original->shift_id,
            'transaction_id' => $original->transaction_id,
            'settlement_id' => $original->settlement_id,
            'reverses_entry_id' => $original->id,
            'description' => $description,
            'created_by' => $actor?->id,
        ]);
    }
}
