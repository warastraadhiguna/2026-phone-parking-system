<?php

namespace App\Domain\Reconciliation\Internal;

use App\Domain\CashLedger\Services\CashBalances;
use App\Domain\Reconciliation\Enums\LineDimension;
use App\Domain\Reconciliation\Enums\MismatchCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Computes one business day (master doc §14) directly from the source tables with SQL. It
 * deliberately does not reuse the application's derived values, so it can find where they
 * disagree.
 *
 * The day is the WIB business date of `transaction_time_server`, which is the same clock as the
 * ledger's `created_at` (both are written in one DB transaction). Offline transactions therefore
 * count on the day they reached the server.
 *
 *   expected_cash   CASH, COMPLETED or VOID_REQUESTED       voided_cash   CASH, VOIDED
 *   qris_expected   QRIS, COMPLETED or VOID_REQUESTED       qris_open     QRIS, WAITING_PAYMENT
 *   qris_paid / qris_refunded   PAID payments of the day's transactions / manual refunds on them
 *   qris_difference = (paid − refunded) − expected          total_revenue = expected_cash + qris_expected
 *   ledger_* / cash_deposited   ledger entries created that day; cash_outstanding = balance at day end
 */
final class ReconciliationCalculator
{
    public function __construct(private readonly CashBalances $balances) {}

    /**
     * @return array{attendants: array<int, array<string, mixed>>, locations: array<int, array<string, mixed>>, mismatches: list<array<string, mixed>>}
     */
    public function compute(CarbonImmutable $start, CarbonImmutable $end): array
    {
        // Timestamps generated here (never user input), written as literals: PDO native prepares do
        // not allow one named parameter to appear several times.
        $window = ['start' => self::literal($start), 'end' => self::literal($end)];

        $attendants = $this->transactionMetrics('attendant_id', $window);
        foreach ($this->ledgerMetrics($window) as $id => $ledger) {
            $attendants[$id] = [...($attendants[$id] ?? $this->emptyMetrics()), ...$ledger];
        }
        foreach ($attendants as $id => $row) {
            $attendants[$id] = [...['ledger_cash_in' => 0, 'ledger_reversals' => 0, 'cash_deposited' => 0, 'cash_outstanding' => 0], ...$row];
        }
        $locations = $this->transactionMetrics('location_id', $window);

        return [
            'attendants' => $this->labelled($attendants, 'parking_attendants', 'attendant_code'),
            'locations' => $this->labelled($locations, 'parking_locations', 'location_code'),
            'mismatches' => $this->mismatches($window),
        ];
    }

    /**
     * @param  array{start: string, end: string}  $window
     * @return array<int, array<string, mixed>>
     */
    private function transactionMetrics(string $key, array $window): array
    {
        $rows = DB::select(<<<SQL
            SELECT t.{$key} AS k,
                   COUNT(*) AS transaction_count,
                   COALESCE(SUM(t.charged_tariff_amount) FILTER (WHERE t.payment_method = 'CASH' AND t.status IN ('COMPLETED', 'VOID_REQUESTED')), 0) AS expected_cash,
                   COALESCE(SUM(t.charged_tariff_amount) FILTER (WHERE t.payment_method = 'CASH' AND t.status = 'VOIDED'), 0) AS voided_cash,
                   COALESCE(SUM(t.charged_tariff_amount) FILTER (WHERE t.payment_method = 'QRIS' AND t.status IN ('COMPLETED', 'VOID_REQUESTED')), 0) AS qris_expected,
                   COALESCE(SUM(p.amount) FILTER (WHERE p.status = 'PAID'), 0) AS qris_paid,
                   COALESCE(SUM(r.refunded) FILTER (WHERE p.status = 'PAID'), 0) AS qris_refunded,
                   COALESCE(SUM(t.charged_tariff_amount) FILTER (WHERE t.payment_method = 'QRIS' AND t.status = 'WAITING_PAYMENT'), 0) AS qris_open
            FROM parking_transactions t
            LEFT JOIN payments p ON p.transaction_id = t.id
            LEFT JOIN (SELECT payment_id, SUM(amount) AS refunded FROM payment_adjustments GROUP BY payment_id) r ON r.payment_id = p.id
            WHERE t.transaction_time_server >= {$window['start']} AND t.transaction_time_server < {$window['end']}
            GROUP BY t.{$key}
            SQL, []);

        $out = [];
        foreach ($rows as $r) {
            $m = [
                'transaction_count' => (int) $r->transaction_count,
                'expected_cash' => (int) $r->expected_cash,
                'voided_cash' => (int) $r->voided_cash,
                'qris_expected' => (int) $r->qris_expected,
                'qris_paid' => (int) $r->qris_paid,
                'qris_refunded' => (int) $r->qris_refunded,
                'qris_open' => (int) $r->qris_open,
            ];
            $m['qris_difference'] = $m['qris_paid'] - $m['qris_refunded'] - $m['qris_expected'];
            $m['total_revenue'] = $m['expected_cash'] + $m['qris_expected'];
            $out[(int) $r->k] = $m;
        }

        return $out;
    }

    /**
     * Ledger movements of the day and the balance at day end, per attendant with activity or cash.
     *
     * @param  array{start: string, end: string}  $window
     * @return array<int, array<string, int>>
     */
    private function ledgerMetrics(array $window): array
    {
        $rows = DB::select(<<<SQL
            SELECT attendant_id AS k,
                   COALESCE(SUM(amount) FILTER (WHERE type = 'PARKING_CASH_IN' AND created_at >= {$window['start']}), 0) AS cash_in,
                   COALESCE(SUM(amount) FILTER (WHERE type = 'REVERSAL' AND created_at >= {$window['start']}), 0) AS reversals,
                   COALESCE(-SUM(amount) FILTER (WHERE type = 'SETTLEMENT_OUT' AND created_at >= {$window['start']}), 0) AS deposited,
                   COALESCE(SUM(amount), 0) AS outstanding,
                   COUNT(*) FILTER (WHERE created_at >= {$window['start']}) AS moves
            FROM cash_ledger_entries
            WHERE created_at < {$window['end']}
            GROUP BY attendant_id
            HAVING COUNT(*) FILTER (WHERE created_at >= {$window['start']}) > 0 OR COALESCE(SUM(amount), 0) <> 0
            SQL, []);

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->k] = [
                'ledger_cash_in' => (int) $r->cash_in,
                'ledger_reversals' => (int) $r->reversals,
                'cash_deposited' => (int) $r->deposited,
                'cash_outstanding' => (int) $r->outstanding,
            ];
        }

        return $out;
    }

    /**
     * @param  array{start: string, end: string}  $window
     * @return list<array<string, mixed>>
     */
    private function mismatches(array $window): array
    {
        $found = [];
        $add = function (MismatchCode $code, string $sql, array $bindings, string $entityType) use (&$found): void {
            foreach (DB::select($sql, $bindings) as $r) {
                $found[] = [
                    'code' => $code->value,
                    'severity' => $code->severity()->value,
                    'entity_type' => $entityType,
                    'entity_id' => (string) $r->entity_id,
                    'reference' => $r->reference ?? null,
                    'expected_amount' => isset($r->expected) ? (int) $r->expected : null,
                    'actual_amount' => isset($r->actual) ? (int) $r->actual : null,
                    'details' => json_encode(array_filter(['attendant_id' => isset($r->attendant_id) ? (int) $r->attendant_id : null, 'status' => $r->status ?? null], fn ($v) => $v !== null) ?: new \stdClass, JSON_THROW_ON_ERROR),
                ];
            }
        };

        $tx = "t.transaction_time_server >= {$window['start']} AND t.transaction_time_server < {$window['end']}";
        $cols = 't.transaction_uuid AS entity_id, t.transaction_number AS reference, t.attendant_id, t.status';

        $add(MismatchCode::CASH_WITHOUT_LEDGER, "SELECT {$cols}, t.charged_tariff_amount AS expected FROM parking_transactions t
            WHERE {$tx} AND t.payment_method = 'CASH'
              AND NOT EXISTS (SELECT 1 FROM cash_ledger_entries l WHERE l.transaction_id = t.id AND l.type = 'PARKING_CASH_IN')", [], 'parking_transaction');

        $add(MismatchCode::CASH_LEDGER_AMOUNT, "SELECT {$cols}, t.charged_tariff_amount AS expected, l.amount AS actual FROM parking_transactions t
            JOIN cash_ledger_entries l ON l.transaction_id = t.id AND l.type = 'PARKING_CASH_IN'
            WHERE {$tx} AND l.amount <> t.charged_tariff_amount", [], 'parking_transaction');

        $add(MismatchCode::VOIDED_CASH_NOT_REVERSED, "SELECT {$cols}, t.charged_tariff_amount AS expected FROM parking_transactions t
            JOIN cash_ledger_entries l ON l.transaction_id = t.id AND l.type = 'PARKING_CASH_IN'
            WHERE {$tx} AND t.payment_method = 'CASH' AND t.status = 'VOIDED'
              AND NOT EXISTS (SELECT 1 FROM cash_ledger_entries r WHERE r.reverses_entry_id = l.id)", [], 'parking_transaction');

        $add(MismatchCode::QRIS_WITHOUT_PAYMENT, "SELECT {$cols}, t.charged_tariff_amount AS expected FROM parking_transactions t
            WHERE {$tx} AND t.payment_method = 'QRIS' AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.transaction_id = t.id)", [], 'parking_transaction');

        $add(MismatchCode::QRIS_COMPLETED_UNPAID, "SELECT {$cols}, t.charged_tariff_amount AS expected FROM parking_transactions t
            JOIN payments p ON p.transaction_id = t.id
            WHERE {$tx} AND t.payment_method = 'QRIS' AND t.status IN ('COMPLETED', 'VOID_REQUESTED', 'VOIDED') AND p.status <> 'PAID'", [], 'parking_transaction');

        $add(MismatchCode::QRIS_PAYMENT_AMOUNT, "SELECT {$cols}, t.charged_tariff_amount AS expected, p.amount AS actual FROM parking_transactions t
            JOIN payments p ON p.transaction_id = t.id
            WHERE {$tx} AND p.amount <> t.charged_tariff_amount", [], 'parking_transaction');

        $refunded = '(SELECT COALESCE(SUM(a.amount), 0) FROM payment_adjustments a WHERE a.payment_id = p.id)';
        $add(MismatchCode::QRIS_PAID_NOT_COMPLETED, "SELECT {$cols}, p.amount AS expected, {$refunded} AS actual FROM parking_transactions t
            JOIN payments p ON p.transaction_id = t.id
            WHERE {$tx} AND p.status = 'PAID' AND t.status = 'CANCELLED' AND {$refunded} < p.amount", [], 'parking_transaction');

        $add(MismatchCode::QRIS_PAID_VOIDED_NOT_REFUNDED, "SELECT {$cols}, p.amount AS expected, {$refunded} AS actual FROM parking_transactions t
            JOIN payments p ON p.transaction_id = t.id
            WHERE {$tx} AND p.status = 'PAID' AND t.status = 'VOIDED' AND {$refunded} < p.amount", [], 'parking_transaction');

        $add(MismatchCode::QRIS_STUCK, "SELECT {$cols}, t.charged_tariff_amount AS expected FROM parking_transactions t
            LEFT JOIN payments p ON p.transaction_id = t.id
            WHERE {$tx} AND t.payment_method = 'QRIS' AND t.status = 'WAITING_PAYMENT'
              AND COALESCE(p.expired_at, t.transaction_time_server) < now() - interval '1 hour'", [], 'parking_transaction');

        $add(MismatchCode::SETTLEMENT_LEDGER, "SELECT s.settlement_uuid AS entity_id, s.settlement_number AS reference, s.attendant_id, s.status,
                   s.verified_amount AS expected, -l.amount AS actual
            FROM cash_settlements s
            LEFT JOIN cash_ledger_entries l ON l.settlement_id = s.id AND l.type = 'SETTLEMENT_OUT'
            WHERE s.status = 'VERIFIED' AND s.decided_at >= {$window['start']} AND s.decided_at < {$window['end']}
              AND (l.id IS NULL OR -l.amount <> s.verified_amount)", [], 'cash_settlement');

        $add(MismatchCode::SETTLEMENT_PENDING, "SELECT s.settlement_uuid AS entity_id, s.settlement_number AS reference, s.attendant_id, s.status, s.amount AS expected
            FROM cash_settlements s
            WHERE s.status = 'SUBMITTED' AND s.submitted_at < {$window['end']} AND s.submitted_at < now() - interval '24 hours'", [], 'cash_settlement');

        foreach ($this->balances->mismatches() as $m) {
            $found[] = [
                'code' => MismatchCode::BALANCE_DRIFT->value,
                'severity' => MismatchCode::BALANCE_DRIFT->severity()->value,
                'entity_type' => 'parking_attendant',
                'entity_id' => (string) $m['attendant_id'],
                'reference' => null,
                'expected_amount' => $m['ledger'],
                'actual_amount' => $m['derived'],
                'details' => json_encode(['attendant_id' => $m['attendant_id']], JSON_THROW_ON_ERROR),
            ];
        }

        return $found;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function labelled(array $rows, string $table, string $codeColumn): array
    {
        if ($rows === []) {
            return [];
        }
        $names = DB::table($table)->whereIn('id', array_keys($rows))->get(['id', $codeColumn, 'name'])->keyBy('id');
        foreach ($rows as $id => $row) {
            $n = $names->get($id);
            $rows[$id] = [...$this->emptyMetrics(), ...$row, 'label' => $n === null ? "#{$id}" : "{$n->{$codeColumn}} — {$n->name}"];
        }
        ksort($rows);

        return $rows;
    }

    /** @return array<string, int> */
    private function emptyMetrics(): array
    {
        return [
            'transaction_count' => 0, 'expected_cash' => 0, 'voided_cash' => 0, 'qris_expected' => 0, 'qris_paid' => 0,
            'qris_refunded' => 0, 'qris_difference' => 0, 'qris_open' => 0, 'total_revenue' => 0,
        ];
    }

    private static function literal(CarbonImmutable $at): string
    {
        return "'".$at->utc()->format('Y-m-d H:i:s.u')."+00'::timestamptz";
    }

    public static function dimensionFor(string $group): LineDimension
    {
        return $group === 'attendants' ? LineDimension::ATTENDANT : LineDimension::LOCATION;
    }
}
