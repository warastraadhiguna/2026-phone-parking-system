<?php

/*
| Performance benchmark (Phase 11). Seeds a synthetic month and times the heavy read paths.
|
|   docker compose exec -e DB_DATABASE=pati_parking_perf app php artisan tinker --execute="require 'tests/Performance/benchmark.php';"
|
| Refuses to run unless the database name ends with "_perf". Never point it at real data.
| Size: PERF_ATTENDANTS (200) × PERF_DAYS (30) × PERF_TX_PER_SHIFT (50) ≈ 300k transactions,
| ~15% QRIS, 1% flagged; one closed shift per attendant per day, ledger rows for every cash tx.
*/

use App\Domain\CashLedger\Services\CashBalances;
use App\Domain\FraudReview\Actions\CollectAnomalies;
use App\Domain\ParkingTransaction\Services\MovementCheck;
use App\Domain\Reconciliation\Actions\RunReconciliation;
use App\Domain\Reporting\Data\ReportParams;
use App\Domain\Reporting\Enums\ReportType;
use App\Domain\Reporting\Services\DashboardMetrics;
use App\Domain\Reporting\Services\ReportCatalog;
use App\Support\Geo\GpsFix;
use App\Support\Time\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

$database = (string) DB::connection()->getDatabaseName();
if (! str_ends_with($database, '_perf')) {
    throw new RuntimeException("Refusing to run on database {$database}: the name must end with _perf.");
}

$attendants = (int) (getenv('PERF_ATTENDANTS') ?: 200);
$days = (int) (getenv('PERF_DAYS') ?: 30);
$perShift = (int) (getenv('PERF_TX_PER_SHIFT') ?: 50);
config(['cache.default' => 'array']);

$time = function (string $label, callable $fn) {
    $t = microtime(true);
    $result = $fn();
    printf("%-48s %9.1f ms\n", $label, (microtime(true) - $t) * 1000);

    return $result;
};

echo "Database {$database}: resetting schema…\n";
Artisan::call('migrate:fresh', ['--force' => true]);
Artisan::call('identity:sync-roles');

$time("seed {$attendants} attendants × {$days} days × {$perShift} tx", function () use ($attendants, $days, $perShift) {
    DB::statement("INSERT INTO users (username, name, password, account_type, status, created_at, updated_at)
        SELECT 'jp-'||lpad(g::text, 6, '0'), 'Jukir '||g, 'x', 'ATTENDANT', 'ACTIVE', now(), now() FROM generate_series(1, ?) g", [$attendants]);
    DB::statement("INSERT INTO users (username, name, password, account_type, status, created_at, updated_at) VALUES ('perf-admin', 'Admin', 'x', 'STAFF', 'ACTIVE', now(), now())");
    DB::statement("INSERT INTO parking_attendants (attendant_code, user_id, name, identity_number, phone, status, registered_at, created_at, updated_at)
        SELECT upper(u.username), u.id, u.name, '3318'||lpad(u.id::text, 12, '0'), '0812'||lpad(u.id::text, 8, '0'), 'ACTIVE', current_date - 400, now(), now()
        FROM users u WHERE u.account_type = 'ATTENDANT'");
    DB::statement("INSERT INTO parking_locations (location_code, name, address, latitude, longitude, geofence_radius_m, location_type, status, motorcycle_capacity, car_capacity, created_at, updated_at)
        SELECT 'PL-'||lpad(g::text, 4, '0'), 'Lokasi '||g, 'Pati', -6.70 - g * 0.001, 111.00 + g * 0.001, 50, 'ON_STREET', 'ACTIVE', 30, 10, now(), now()
        FROM generate_series(1, ?) g", [max(1, intdiv($attendants, 2))]);
    DB::statement("INSERT INTO devices (device_uuid, attendant_id, status, registered_at, approved_at, created_at, updated_at)
        SELECT gen_random_uuid(), a.id, 'ACTIVE', now() - interval '400 days', now() - interval '400 days', now(), now() FROM parking_attendants a");

    // One closed 8-hour shift per attendant per day; attendants share locations two by two.
    DB::statement("INSERT INTO shifts (shift_uuid, attendant_id, location_id, device_id, status, offline_created, started_at_device, started_at_server,
            start_latitude, start_longitude, start_gps_accuracy_m, start_geofence_result, ended_at_device, ended_at_server, end_geofence_result,
            review_flags, start_payload_hash, end_payload_hash, created_at, updated_at)
        SELECT gen_random_uuid(), a.id, l.id, d.id, 'CLOSED', false, t, t, l.latitude, l.longitude, 8, 'INSIDE', t + interval '8 hours', t + interval '8 hours', 'INSIDE',
               '[]', md5(a.id::text||t::text)||md5(t::text), md5(t::text)||md5(a.id::text), t, t
        FROM parking_attendants a
        JOIN devices d ON d.attendant_id = a.id
        JOIN parking_locations l ON l.id = ((a.id - 1) / 2) % (SELECT count(*) FROM parking_locations) + 1
        CROSS JOIN generate_series(1, ?) day
        CROSS JOIN LATERAL (SELECT (date_trunc('day', now() AT TIME ZONE 'Asia/Jakarta') - (day - 1) * interval '1 day' + interval '7 hours') AT TIME ZONE 'Asia/Jakarta' AS t) x
        WHERE x.t < now()", [$days]);

    DB::statement("INSERT INTO parking_transactions (transaction_uuid, transaction_number, shift_id, attendant_id, location_id, device_id, sync_sequence,
            vehicle_type, charged_tariff_amount, server_expected_tariff_amount, tariff_difference_amount, payment_method, status,
            transaction_time_device, transaction_time_server, latitude, longitude, gps_accuracy_m, geofence_result, offline_created, review_flags,
            payload_hash, created_at, updated_at)
        SELECT gen_random_uuid(), 'TRX-P'||s.id||'-'||g, s.id, s.attendant_id, s.location_id, s.device_id, s.id * 1000 + g,
               CASE WHEN g % 5 = 0 THEN 'CAR' ELSE 'MOTORCYCLE' END,
               CASE WHEN g % 5 = 0 THEN 5000 ELSE 2000 END, CASE WHEN g % 5 = 0 THEN 5000 ELSE 2000 END, 0,
               CASE WHEN g % 7 = 0 THEN 'QRIS' ELSE 'CASH' END, 'COMPLETED',
               s.started_at_server + g * interval '9 minutes', s.started_at_server + g * interval '9 minutes',
               s.start_latitude, s.start_longitude, 8, 'INSIDE', false,
               CASE WHEN g % 100 = 0 THEN '[\"OUTSIDE_GEOFENCE\"]'::jsonb ELSE '[]'::jsonb END,
               md5(s.id::text||'-'||g)||md5(g::text), s.started_at_server, s.started_at_server
        FROM shifts s CROSS JOIN generate_series(1, ?) g
        WHERE s.started_at_server + g * interval '9 minutes' < now()", [$perShift]);

    DB::statement("INSERT INTO payments (payment_uuid, transaction_id, provider, provider_order_id, provider_reference, payment_method, amount, status,
            expired_at, paid_at, charge_attempts, created_at, updated_at)
        SELECT u, t.id, 'FAKE', u::text, 'ref-'||t.id, 'QRIS', t.charged_tariff_amount, 'PAID', t.transaction_time_server + interval '15 minutes',
               t.transaction_time_server + interval '1 minute', 1, t.transaction_time_server, t.transaction_time_server
        FROM (SELECT gen_random_uuid() AS u, * FROM parking_transactions WHERE payment_method = 'QRIS') t");

    DB::statement("INSERT INTO cash_ledger_entries (attendant_id, shift_id, transaction_id, type, amount, balance_after, description, created_at)
        SELECT attendant_id, shift_id, id, 'PARKING_CASH_IN', charged_tariff_amount,
               SUM(charged_tariff_amount) OVER (PARTITION BY attendant_id ORDER BY transaction_time_server, id), transaction_number, transaction_time_server
        FROM parking_transactions WHERE payment_method = 'CASH'");
    DB::statement('INSERT INTO attendant_cash_balances (attendant_id, balance, last_entry_id, updated_at)
        SELECT attendant_id, SUM(amount), MAX(id), now() FROM cash_ledger_entries GROUP BY attendant_id');
    DB::statement('ANALYZE');
});

printf("rows: %d transactions, %d ledger entries, %d payments\n",
    DB::scalar('SELECT count(*) FROM parking_transactions'), DB::scalar('SELECT count(*) FROM cash_ledger_entries'), DB::scalar('SELECT count(*) FROM payments'));

$yesterday = now(BusinessTime::timezone())->subDay()->toDateString();
$monthStart = now(BusinessTime::timezone())->subDays($days - 1)->toDateString();
$today = now(BusinessTime::timezone())->toDateString();
$busy = (int) DB::scalar('SELECT attendant_id FROM parking_transactions GROUP BY 1 ORDER BY count(*) DESC LIMIT 1');

echo "\nTimings (cold cache, one run each):\n";
$time('dashboard operational', fn () => app(DashboardMetrics::class)->operational());
$time('dashboard executive (month, trend, top 5)', fn () => app(DashboardMetrics::class)->executive());
$time("reconciliation {$yesterday}", fn () => app(RunReconciliation::class)->handle($yesterday));
$drain = function (ReportType $type, ReportParams $p) {
    $n = 0;
    foreach (app(ReportCatalog::class)->rows($type, $p) as $row) {
        $n++;
    }

    return $n;
};
$time('report revenue by location, whole period', fn () => $drain(ReportType::REVENUE_BY_LOCATION, new ReportParams($monthStart, $today)));
$time('report revenue by date, whole period', fn () => $drain(ReportType::REVENUE_BY_DATE, new ReportParams($monthStart, $today)));
$time('report transaction detail, one day (all rows)', fn () => $drain(ReportType::TRANSACTION_DETAIL, new ReportParams($yesterday, $yesterday)));
$time('admin transaction list: 1 location, 25 rows + totals', function () {
    $q = DB::table('parking_transactions')->where('location_id', 1);
    $q->clone()->whereIn('status', ['COMPLETED', 'VOID_REQUESTED'])->selectRaw('count(*), sum(charged_tariff_amount)')->first();

    return $q->orderByDesc('transaction_time_server')->limit(25)->get();
});
$time('movement check (busiest attendant)', fn () => app(MovementCheck::class)->isImpossible($busy, new GpsFix(-6.70, 111.0, 8), CarbonImmutable::now()));
$time('cash balance verification (all attendants)', fn () => app(CashBalances::class)->mismatches());
$time('collect anomalies (first run)', fn () => app(CollectAnomalies::class)->handle());
$time('collect anomalies (idempotent re-run)', fn () => app(CollectAnomalies::class)->handle());

echo "\nPlans of the transaction list and movement check:\n";
foreach ([
    'SELECT * FROM parking_transactions WHERE location_id = 1 ORDER BY transaction_time_server DESC LIMIT 25',
    "SELECT latitude, longitude, transaction_time_device FROM parking_transactions WHERE attendant_id = {$busy} AND latitude IS NOT NULL AND transaction_time_device <= now() ORDER BY transaction_time_device DESC LIMIT 1",
] as $sql) {
    foreach (DB::select('EXPLAIN (ANALYZE, COSTS OFF, TIMING OFF) '.$sql) as $line) {
        echo '  '.((array) $line)['QUERY PLAN']."\n";
    }
    echo "\n";
}
Cache::flush();
