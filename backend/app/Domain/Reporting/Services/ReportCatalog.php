<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Audit\Services\AuditLogBrowser;
use App\Domain\Reporting\Data\ReportParams;
use App\Domain\Reporting\Enums\ReportType;
use Illuminate\Support\Facades\DB;

/**
 * Report definitions (master doc §32): columns and rows for each report type. Rows are
 * streamed (cursor), so exports of any size never load everything into memory. Money columns
 * are integer rupiah. Revenue uses the same definition as dashboards and reconciliation.
 */
final class ReportCatalog
{
    private const REVENUE = "t.status IN ('COMPLETED', 'VOID_REQUESTED')";

    private const DAY = "(t.transaction_time_server AT TIME ZONE 'Asia/Jakarta')::date";

    public function __construct(private readonly AuditLogBrowser $audit) {}

    /** @return array<string, string> column key => label */
    public function columns(ReportType $type): array
    {
        $money = ['transactions' => 'Jumlah transaksi', 'cash' => 'Tunai (Rp)', 'qris' => 'QRIS (Rp)', 'total' => 'Total (Rp)'];

        return match ($type) {
            ReportType::REVENUE_BY_DATE => ['date' => 'Tanggal', ...$money],
            ReportType::REVENUE_BY_LOCATION => ['location_code' => 'Kode lokasi', 'location_name' => 'Nama lokasi', ...$money],
            ReportType::REVENUE_BY_ATTENDANT => ['attendant_code' => 'Kode jukir', 'attendant_name' => 'Nama jukir', ...$money],
            ReportType::REVENUE_BY_VEHICLE => ['vehicle_type' => 'Jenis kendaraan', ...$money],
            ReportType::CASH_VS_QRIS => ['date' => 'Tanggal', 'cash' => 'Tunai (Rp)', 'qris' => 'QRIS (Rp)', 'cash_pct' => 'Tunai (%)', 'qris_pct' => 'QRIS (%)'],
            ReportType::CASH_OUTSTANDING => ['attendant_code' => 'Kode jukir', 'attendant_name' => 'Nama jukir', 'outstanding' => 'Belum disetor (Rp)', 'pending_settlement' => 'Setoran menunggu (Rp)', 'last_deposit_at' => 'Setoran terakhir'],
            ReportType::SETTLEMENTS => ['settlement_number' => 'No. setoran', 'submitted_at' => 'Diajukan', 'attendant_code' => 'Kode jukir', 'amount' => 'Diajukan (Rp)', 'verified_amount' => 'Diterima (Rp)', 'status' => 'Status', 'decided_by' => 'Diputuskan oleh', 'decided_at' => 'Diputuskan', 'decision_note' => 'Catatan'],
            ReportType::RECONCILIATION => ['business_date' => 'Tanggal', 'total_revenue' => 'Pendapatan (Rp)', 'expected_cash' => 'Tunai diharapkan (Rp)', 'cash_deposited' => 'Disetor (Rp)', 'cash_outstanding' => 'Belum disetor (Rp)', 'qris_expected' => 'QRIS diharapkan (Rp)', 'qris_paid' => 'QRIS lunas (Rp)', 'qris_difference' => 'Selisih QRIS (Rp)', 'mismatch_count' => 'Ketidaksesuaian', 'error_count' => 'Kesalahan'],
            ReportType::TRANSACTION_DETAIL => ['transaction_number' => 'No. transaksi', 'time' => 'Waktu (server)', 'attendant_code' => 'Kode jukir', 'location_code' => 'Lokasi', 'vehicle_type' => 'Kendaraan', 'vehicle_plate' => 'Plat', 'payment_method' => 'Metode', 'charged' => 'Ditagih (Rp)', 'expected' => 'Tarif server (Rp)', 'status' => 'Status', 'offline' => 'Offline', 'flags' => 'Tanda'],
            ReportType::ANOMALIES => ['occurred_at' => 'Waktu', 'severity' => 'Tingkat', 'source' => 'Sumber', 'code' => 'Sinyal', 'reference' => 'Referensi', 'attendant_code' => 'Kode jukir', 'amount' => 'Nominal (Rp)', 'status' => 'Status', 'decided_by' => 'Ditinjau oleh', 'decision_note' => 'Catatan'],
            ReportType::AUDIT_LOG => ['occurred_at' => 'Waktu', 'actor' => 'Pelaku', 'action' => 'Aksi', 'entity_type' => 'Jenis data', 'entity_id' => 'ID data', 'ip_address' => 'IP', 'request_id' => 'Request ID'],
        };
    }

    /** @return iterable<array<string, scalar|null>> */
    public function rows(ReportType $type, ReportParams $p): iterable
    {
        $window = [$p->start(), $p->end()];
        [$filterSql, $filterBindings] = $this->transactionFilters($p);

        $revenueBy = function (string $select, string $group, string $join = '') use ($window, $filterSql, $filterBindings): iterable {
            return $this->cursor(<<<SQL
                SELECT {$select},
                       COUNT(*) AS transactions,
                       COALESCE(SUM(t.charged_tariff_amount) FILTER (WHERE t.payment_method = 'CASH'), 0) AS cash,
                       COALESCE(SUM(t.charged_tariff_amount) FILTER (WHERE t.payment_method = 'QRIS'), 0) AS qris,
                       COALESCE(SUM(t.charged_tariff_amount), 0) AS total
                FROM parking_transactions t {$join}
                WHERE {$this->revenueSql()} AND t.transaction_time_server >= ? AND t.transaction_time_server < ? {$filterSql}
                GROUP BY {$group} ORDER BY {$group}
                SQL, [...$window, ...$filterBindings]);
        };

        return match ($type) {
            ReportType::REVENUE_BY_DATE => $revenueBy(self::DAY.' AS date', '1'),
            ReportType::REVENUE_BY_LOCATION => $revenueBy('l.location_code, l.name AS location_name', 'l.location_code, l.name', 'JOIN parking_locations l ON l.id = t.location_id'),
            ReportType::REVENUE_BY_ATTENDANT => $revenueBy('a.attendant_code, a.name AS attendant_name', 'a.attendant_code, a.name', 'JOIN parking_attendants a ON a.id = t.attendant_id'),
            ReportType::REVENUE_BY_VEHICLE => $revenueBy('t.vehicle_type', 't.vehicle_type'),
            ReportType::CASH_VS_QRIS => $this->cashVsQris($revenueBy(self::DAY.' AS date', '1')),
            ReportType::CASH_OUTSTANDING => $this->cursor(<<<'SQL'
                SELECT a.attendant_code, a.name AS attendant_name, b.balance AS outstanding,
                       COALESCE((SELECT SUM(s.amount) FROM cash_settlements s WHERE s.attendant_id = a.id AND s.status = 'SUBMITTED'), 0) AS pending_settlement,
                       (SELECT MAX(s.decided_at) FROM cash_settlements s WHERE s.attendant_id = a.id AND s.status = 'VERIFIED') AS last_deposit_at
                FROM attendant_cash_balances b JOIN parking_attendants a ON a.id = b.attendant_id
                WHERE b.balance <> 0 ORDER BY b.balance DESC
                SQL),
            ReportType::SETTLEMENTS => $this->cursor(<<<'SQL'
                SELECT s.settlement_number, s.submitted_at, a.attendant_code, s.amount, s.verified_amount, s.status, u.username AS decided_by, s.decided_at, s.decision_note
                FROM cash_settlements s JOIN parking_attendants a ON a.id = s.attendant_id LEFT JOIN users u ON u.id = s.decided_by
                WHERE s.submitted_at >= ? AND s.submitted_at < ? AND (?::bigint IS NULL OR s.attendant_id = ?::bigint)
                ORDER BY s.submitted_at
                SQL, [...$window, $p->attendantId, $p->attendantId]),
            ReportType::RECONCILIATION => $this->cursor(<<<'SQL'
                SELECT r.business_date, r.total_revenue, r.expected_cash, r.cash_deposited, r.cash_outstanding, r.qris_expected,
                       r.qris_paid - r.qris_refunded AS qris_paid, r.qris_difference, r.mismatch_count, r.error_count
                FROM reconciliation_runs r
                WHERE r.id IN (SELECT max(id) FROM reconciliation_runs WHERE business_date BETWEEN ?::date AND ?::date GROUP BY business_date)
                ORDER BY r.business_date
                SQL, [$p->from, $p->to]),
            ReportType::TRANSACTION_DETAIL => $this->cursor(<<<SQL
                SELECT t.transaction_number, t.transaction_time_server AS time, a.attendant_code, l.location_code, t.vehicle_type, t.vehicle_plate,
                       t.payment_method, t.charged_tariff_amount AS charged, t.server_expected_tariff_amount AS expected, t.status,
                       CASE WHEN t.offline_created THEN 'ya' ELSE 'tidak' END AS offline,
                       array_to_string(ARRAY(SELECT jsonb_array_elements_text(t.review_flags)), ' ') AS flags
                FROM parking_transactions t
                JOIN parking_attendants a ON a.id = t.attendant_id JOIN parking_locations l ON l.id = t.location_id
                WHERE t.transaction_time_server >= ? AND t.transaction_time_server < ? {$filterSql}
                ORDER BY t.transaction_time_server, t.id
                SQL, [...$window, ...$filterBindings]),
            ReportType::ANOMALIES => $this->cursor(<<<'SQL'
                SELECT r.occurred_at, r.severity, r.source, r.code, r.reference, a.attendant_code, r.amount, r.status, u.username AS decided_by, r.decision_note
                FROM anomaly_reviews r LEFT JOIN parking_attendants a ON a.id = r.attendant_id LEFT JOIN users u ON u.id = r.decided_by
                WHERE r.occurred_at >= ? AND r.occurred_at < ? AND (?::bigint IS NULL OR r.attendant_id = ?::bigint)
                ORDER BY r.occurred_at
                SQL, [...$window, $p->attendantId, $p->attendantId]),
            ReportType::AUDIT_LOG => $this->audit->export($p->start(), $p->end()),
        };
    }

    /**
     * @param  iterable<array<string, scalar|null>>  $rows
     * @return iterable<array<string, scalar|null>>
     */
    private function cashVsQris(iterable $rows): iterable
    {
        foreach ($rows as $r) {
            $total = (int) $r['total'];
            yield [
                'date' => $r['date'],
                'cash' => $r['cash'],
                'qris' => $r['qris'],
                'cash_pct' => $total > 0 ? round((int) $r['cash'] * 100 / $total, 1) : 0,
                'qris_pct' => $total > 0 ? round((int) $r['qris'] * 100 / $total, 1) : 0,
            ];
        }
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function transactionFilters(ReportParams $p): array
    {
        $sql = '';
        $bindings = [];
        if ($p->locationId !== null) {
            $sql .= ' AND t.location_id = ?';
            $bindings[] = $p->locationId;
        }
        if ($p->attendantId !== null) {
            $sql .= ' AND t.attendant_id = ?';
            $bindings[] = $p->attendantId;
        }
        if ($p->paymentMethod !== null) {
            $sql .= ' AND t.payment_method = ?';
            $bindings[] = $p->paymentMethod;
        }

        return [$sql, $bindings];
    }

    /** PostgreSQL returns SUM(bigint) as a numeric string: hand numbers on as numbers. */
    private static function number(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^-?\d{1,18}$/', $value) === 1) {
            return (int) $value;
        }
        if (is_string($value) && preg_match('/^-?\d{1,15}\.\d+$/', $value) === 1) {
            return (float) $value;
        }

        return $value;
    }

    private function revenueSql(): string
    {
        return self::REVENUE;
    }

    /**
     * @param  list<mixed>  $bindings
     * @return iterable<array<string, scalar|null>>
     */
    private function cursor(string $sql, array $bindings = []): iterable
    {
        foreach (DB::cursor($sql, $bindings) as $row) {
            /** @var array<string, scalar|null> $values */
            $values = array_map(self::number(...), (array) $row);
            yield $values;
        }
    }
}
