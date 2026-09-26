<?php

namespace App\Domain\CashLedger\Internal;

use App\Domain\CashLedger\Enums\LedgerEntryType;
use App\Domain\CashLedger\Models\CashLedgerEntry;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The single writer of the cash ledger. Must run inside the caller's DB transaction.
 *
 * Locks the attendant's derived balance row (creating it on first use) so that concurrent
 * entries for one attendant are serialised and balance_after is always exact.
 */
final class LedgerWriter
{
    /**
     * The attendant's current balance, with the balance row locked until the caller's DB
     * transaction ends (so a check against it stays true for the following append).
     */
    public function lockedBalance(int $attendantId): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Ledger balances must be locked inside a database transaction.');
        }

        DB::statement(
            'INSERT INTO attendant_cash_balances (attendant_id, balance, updated_at) VALUES (?, 0, now()) ON CONFLICT (attendant_id) DO NOTHING',
            [$attendantId],
        );

        return (int) DB::scalar('SELECT balance FROM attendant_cash_balances WHERE attendant_id = ? FOR UPDATE', [$attendantId]);
    }

    /**
     * @param  array{shift_id?: int|null, transaction_id?: int|null, settlement_id?: int|null, reverses_entry_id?: int|null, description?: string|null, created_by?: int|null}  $refs
     */
    public function append(int $attendantId, LedgerEntryType $type, int $amount, array $refs = []): CashLedgerEntry
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Ledger entries must be written inside a database transaction.');
        }

        DB::statement(
            'INSERT INTO attendant_cash_balances (attendant_id, balance, updated_at) VALUES (?, 0, now()) ON CONFLICT (attendant_id) DO NOTHING',
            [$attendantId],
        );
        $current = (int) DB::scalar('SELECT balance FROM attendant_cash_balances WHERE attendant_id = ? FOR UPDATE', [$attendantId]);

        $entry = CashLedgerEntry::create([
            'attendant_id' => $attendantId,
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $current + $amount,
            ...$refs,
        ]);

        DB::update(
            'UPDATE attendant_cash_balances SET balance = ?, last_entry_id = ?, updated_at = now() WHERE attendant_id = ?',
            [$entry->balance_after, $entry->id, $attendantId],
        );

        return $entry;
    }
}
