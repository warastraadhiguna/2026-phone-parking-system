<?php

namespace App\Domain\CashLedger\Services;

use App\Support\Time\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Expected − deposited = outstanding (master doc §3.4, §11), always computed from the ledger.
 *
 *   collected  = cash-ins + reversals + adjustments (what the attendant should have)
 *   deposited  = verified settlements (positive number)
 *   outstanding = collected − deposited (for all time this equals the balance)
 *
 * Totals are available for all time, one shift, or one business day (WIB), because the
 * settlement policy (per shift or per day) is still an owner decision (§62 #11).
 */
final class CashSummary
{
    /** @return array{collected: int, deposited: int, outstanding: int, cash_in: int, reversals: int, adjustments: int} */
    public function forAttendant(int $attendantId): array
    {
        return $this->totals('attendant_id = ?', [$attendantId]);
    }

    /** @return array{collected: int, deposited: int, outstanding: int, cash_in: int, reversals: int, adjustments: int} */
    public function forShift(int $shiftId): array
    {
        return $this->totals('shift_id = ?', [$shiftId]);
    }

    /** @return array{collected: int, deposited: int, outstanding: int, cash_in: int, reversals: int, adjustments: int} */
    public function forDay(int $attendantId, string $date): array
    {
        $start = CarbonImmutable::parse($date, BusinessTime::timezone())->startOfDay()->utc();

        return $this->totals('attendant_id = ? AND created_at >= ? AND created_at < ?', [$attendantId, $start, $start->addDay()]);
    }

    /**
     * @param  list<mixed>  $bindings
     * @return array{collected: int, deposited: int, outstanding: int, cash_in: int, reversals: int, adjustments: int}
     */
    private function totals(string $where, array $bindings): array
    {
        $rows = DB::select("SELECT type, COALESCE(SUM(amount), 0) AS total FROM cash_ledger_entries WHERE {$where} GROUP BY type", $bindings);
        $by = [];
        foreach ($rows as $row) {
            $by[(string) $row->type] = (int) $row->total;
        }

        $cashIn = $by['PARKING_CASH_IN'] ?? 0;
        $reversals = $by['REVERSAL'] ?? 0;
        $adjustments = $by['ADJUSTMENT'] ?? 0;
        $collected = $cashIn + $reversals + $adjustments;
        $deposited = -($by['SETTLEMENT_OUT'] ?? 0);

        return [
            'collected' => $collected,
            'deposited' => $deposited,
            'outstanding' => $collected - $deposited,
            'cash_in' => $cashIn,
            'reversals' => $reversals,
            'adjustments' => $adjustments,
        ];
    }
}
