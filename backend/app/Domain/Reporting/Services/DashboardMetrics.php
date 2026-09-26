<?php

namespace App\Domain\Reporting\Services;

use App\Domain\SystemConfiguration\Enums\SettingKey;
use App\Domain\SystemConfiguration\Services\Settings;
use App\Support\Time\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard figures (master doc §29–§31). Read-only SQL over the source tables. Results are
 * cached for a short time in Redis (derived data only, ADR-0003); PostgreSQL stays the truth.
 *
 * Revenue = transactions COMPLETED or VOID_REQUESTED, by server time in the WIB business day,
 * the same definition as reconciliation (ADR-0012).
 */
final class DashboardMetrics
{
    private const CACHE_SECONDS = 30;

    private const REVENUE = "t.status IN ('COMPLETED', 'VOID_REQUESTED')";

    public function __construct(private readonly Settings $settings) {}

    /** @return array<string, mixed> */
    public function operational(): array
    {
        [$start, $end, $date] = $this->today();

        return Cache::remember("dashboard:operational:{$date}", self::CACHE_SECONDS, function () use ($start, $end, $date) {
            $revenue = $this->revenue($start, $end);
            $reviews = DB::table('anomaly_reviews')->where('status', 'OPEN')->selectRaw('severity, count(*) AS n')->groupBy('severity')->pluck('n', 'severity');
            $pending = DB::table('cash_settlements')->where('status', 'SUBMITTED')->selectRaw('count(*) AS n, coalesce(sum(amount), 0) AS amount')->first();
            $payments = DB::table('payments')->where('created_at', '>=', $start)->where('created_at', '<', $end)
                ->selectRaw("count(*) FILTER (WHERE status = 'FAILED') AS failed, count(*) FILTER (WHERE status = 'EXPIRED') AS expired, count(*) FILTER (WHERE status IN ('CREATED', 'PENDING')) AS open")
                ->first();

            return [
                'date' => $date,
                ...$revenue,
                'active_attendants' => (int) DB::table('shifts')->where('status', 'OPEN')->distinct()->count('attendant_id'),
                'active_locations' => (int) DB::table('shifts')->where('status', 'OPEN')->distinct()->count('location_id'),
                'open_shifts' => (int) DB::table('shifts')->where('status', 'OPEN')->count(),
                'outstanding_cash' => (int) DB::table('attendant_cash_balances')->sum('balance'),
                'pending_settlements' => ['count' => (int) ($pending->n ?? 0), 'amount' => (int) ($pending->amount ?? 0)],
                'payment_failures' => ['failed' => (int) ($payments->failed ?? 0), 'expired' => (int) ($payments->expired ?? 0), 'open' => (int) ($payments->open ?? 0)],
                'anomalies' => ['HIGH' => (int) ($reviews['HIGH'] ?? 0), 'MEDIUM' => (int) ($reviews['MEDIUM'] ?? 0), 'LOW' => (int) ($reviews['LOW'] ?? 0)],
                'locations' => $this->locationsToday($start, $end),
            ];
        });
    }

    /** @return array<string, mixed> */
    public function executive(): array
    {
        [$start, $end, $date] = $this->today();

        return Cache::remember("dashboard:executive:{$date}", self::CACHE_SECONDS, function () use ($start, $end, $date) {
            $monthStart = $start->setTimezone(BusinessTime::timezone())->startOfMonth()->utc();
            $month = $this->revenue($monthStart, $end);
            $target = $this->settings->int(SettingKey::MONTHLY_REVENUE_TARGET);

            $trend = DB::select(<<<SQL
                SELECT (t.transaction_time_server AT TIME ZONE 'Asia/Jakarta')::date AS day,
                       COALESCE(SUM(t.charged_tariff_amount) FILTER (WHERE t.payment_method = 'CASH'), 0) AS cash,
                       COALESCE(SUM(t.charged_tariff_amount) FILTER (WHERE t.payment_method = 'QRIS'), 0) AS qris
                FROM parking_transactions t
                WHERE {$this->revenueCondition()} AND t.transaction_time_server >= ? AND t.transaction_time_server < ?
                GROUP BY 1 ORDER BY 1
                SQL, [$start->subDays(29), $end]);
            $byDay = [];
            foreach ($trend as $row) {
                $byDay[(string) $row->day] = ['cash' => (int) $row->cash, 'qris' => (int) $row->qris];
            }
            $series = [];
            for ($i = 29; $i >= 0; $i--) {
                $d = CarbonImmutable::parse($date, BusinessTime::timezone())->subDays($i)->toDateString();
                $series[] = ['date' => $d, ...($byDay[$d] ?? ['cash' => 0, 'qris' => 0])];
            }

            $top = DB::select(<<<SQL
                SELECT l.id, l.location_code, l.name, COUNT(*) AS transactions, SUM(t.charged_tariff_amount) AS revenue
                FROM parking_transactions t JOIN parking_locations l ON l.id = t.location_id
                WHERE {$this->revenueCondition()} AND t.transaction_time_server >= ? AND t.transaction_time_server < ?
                GROUP BY l.id ORDER BY revenue DESC LIMIT 5
                SQL, [$monthStart, $end]);

            return [
                'date' => $date,
                'today' => $this->revenue($start, $end),
                'month' => $month,
                'target' => $target,
                'target_progress' => $target > 0 ? round($month['total_revenue'] * 100 / $target, 1) : null,
                'outstanding_cash' => (int) DB::table('attendant_cash_balances')->sum('balance'),
                'top_locations' => array_map(fn ($r) => ['id' => (int) $r->id, 'code' => $r->location_code, 'name' => $r->name, 'transactions' => (int) $r->transactions, 'revenue' => (int) $r->revenue], $top),
                'trend' => $series,
                'locations' => $this->locationsToday($start, $end),
            ];
        });
    }

    /** @return array{total_revenue: int, cash_revenue: int, qris_revenue: int, transaction_count: int} */
    private function revenue(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $row = DB::selectOne(<<<SQL
            SELECT COUNT(*) AS n,
                   COALESCE(SUM(t.charged_tariff_amount), 0) AS total,
                   COALESCE(SUM(t.charged_tariff_amount) FILTER (WHERE t.payment_method = 'CASH'), 0) AS cash,
                   COALESCE(SUM(t.charged_tariff_amount) FILTER (WHERE t.payment_method = 'QRIS'), 0) AS qris
            FROM parking_transactions t
            WHERE {$this->revenueCondition()} AND t.transaction_time_server >= ? AND t.transaction_time_server < ?
            SQL, [$start, $end]);

        return [
            'total_revenue' => (int) $row->total,
            'cash_revenue' => (int) $row->cash,
            'qris_revenue' => (int) $row->qris,
            'transaction_count' => (int) $row->n,
        ];
    }

    /**
     * Map points (§31): every location with its status and today's counts. No vehicle tracking.
     *
     * @return list<array<string, mixed>>
     */
    private function locationsToday(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = DB::select(<<<SQL
            SELECT l.id, l.location_code, l.name, l.status, l.latitude, l.longitude,
                   COUNT(t.id) AS transactions, COALESCE(SUM(t.charged_tariff_amount), 0) AS revenue,
                   EXISTS (SELECT 1 FROM shifts s WHERE s.location_id = l.id AND s.status = 'OPEN') AS staffed
            FROM parking_locations l
            LEFT JOIN parking_transactions t ON t.location_id = l.id AND {$this->revenueCondition()}
                 AND t.transaction_time_server >= ? AND t.transaction_time_server < ?
            GROUP BY l.id
            ORDER BY l.location_code
            SQL, [$start, $end]);

        return array_values(array_map(fn ($r) => [
            'id' => (int) $r->id,
            'code' => $r->location_code,
            'name' => $r->name,
            'status' => $r->status,
            'latitude' => $r->latitude === null ? null : (float) $r->latitude,
            'longitude' => $r->longitude === null ? null : (float) $r->longitude,
            'transactions' => (int) $r->transactions,
            'revenue' => (int) $r->revenue,
            'staffed' => (bool) $r->staffed,
        ], $rows));
    }

    private function revenueCondition(): string
    {
        return self::REVENUE;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string} */
    private function today(): array
    {
        $start = CarbonImmutable::now(BusinessTime::timezone())->startOfDay();

        return [$start->utc(), $start->addDay()->utc(), $start->toDateString()];
    }
}
