<?php

namespace App\Domain\CashLedger\Services;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\CashLedger\Models\AttendantCashBalance;
use Illuminate\Support\Facades\DB;

/**
 * Reads the derived balance, and proves or repairs it against the ledger (ADR-0007).
 */
final class CashBalances
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /** Cash the attendant currently holds (expected − deposited), in rupiah. */
    public function of(int $attendantId): int
    {
        return (int) (AttendantCashBalance::query()->whereKey($attendantId)->value('balance') ?? 0);
    }

    /**
     * Attendants whose derived balance differs from SUM(ledger).
     *
     * @return list<array{attendant_id: int, derived: int, ledger: int}>
     */
    public function mismatches(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT COALESCE(l.attendant_id, b.attendant_id) AS attendant_id,
                   COALESCE(b.balance, 0) AS derived,
                   COALESCE(l.total, 0) AS ledger
            FROM (SELECT attendant_id, SUM(amount) AS total FROM cash_ledger_entries GROUP BY attendant_id) l
            FULL OUTER JOIN attendant_cash_balances b ON b.attendant_id = l.attendant_id
            WHERE COALESCE(b.balance, 0) <> COALESCE(l.total, 0)
            ORDER BY 1
            SQL);

        return array_values(array_map(fn ($r) => ['attendant_id' => (int) $r->attendant_id, 'derived' => (int) $r->derived, 'ledger' => (int) $r->ledger], $rows));
    }

    /**
     * Rebuilds the derived rows of mismatching attendants from the ledger, under lock. The ledger
     * is never changed. Audited as a system action.
     *
     * @return list<array{attendant_id: int, derived: int, ledger: int}>
     */
    public function rebuild(): array
    {
        return DB::transaction(function () {
            DB::statement('LOCK TABLE attendant_cash_balances IN SHARE ROW EXCLUSIVE MODE');
            $fixed = $this->mismatches();

            foreach ($fixed as $row) {
                $last = DB::scalar('SELECT MAX(id) FROM cash_ledger_entries WHERE attendant_id = ?', [$row['attendant_id']]);
                DB::statement(
                    'INSERT INTO attendant_cash_balances (attendant_id, balance, last_entry_id, updated_at) VALUES (?, ?, ?, now())
                     ON CONFLICT (attendant_id) DO UPDATE SET balance = EXCLUDED.balance, last_entry_id = EXCLUDED.last_entry_id, updated_at = now()',
                    [$row['attendant_id'], $row['ledger'], $last],
                );
            }

            if ($fixed !== []) {
                $this->audit->handle(AuditAction::CASH_BALANCES_REBUILT, null, 'attendant_cash_balances', null, ['corrected' => $fixed]);
            }

            return $fixed;
        });
    }
}
