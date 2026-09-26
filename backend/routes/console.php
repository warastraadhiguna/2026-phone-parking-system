<?php

use App\Domain\Identity\Models\MobileRefreshToken;
use App\Support\Queue\QueueHealthCheck;
use App\Support\Queue\QueueHeartbeat;
use App\Support\RequestId\RequestId;
use App\Support\Time\BusinessTime;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
| Scheduled tasks. Run by the `scheduler` container (php artisan schedule:work).
| Business schedules (QR expiry, reconciliation, ...) are added by their phases.
*/

// Queue hygiene: keep failed jobs for 30 days for investigation, then prune.
Schedule::command('queue:prune-failed', ['--hours' => 720])->daily()->onOneServer()->name('prune-failed-jobs');
Schedule::command('queue:prune-batches', ['--hours' => 720])->daily()->onOneServer()->name('prune-job-batches');

// Identity: expired access tokens (kept 1 day) and old refresh tokens (MobileRefreshToken::prunable).
Schedule::command('sanctum:prune-expired', ['--hours' => 24])->daily()->onOneServer()->name('prune-expired-access-tokens');
Schedule::command('model:prune', ['--model' => [MobileRefreshToken::class]])->daily()->onOneServer()->name('prune-refresh-tokens');

// Master data: attendants past their registration validity become EXPIRED (login deactivated).
Schedule::command('attendants:expire')->dailyAt('00:05')->timezone(BusinessTime::timezone())->onOneServer()->name('expire-attendants');

// Cash: prove derived balances against the ledger every night (alerts via error log on mismatch).
Schedule::command('cash:verify-balances')->dailyAt('01:00')->timezone(BusinessTime::timezone())->onOneServer()->name('verify-cash-balances');

// Reconciliation of the previous WIB day, after the cash balance verification (exit 1 on integrity errors).
Schedule::command('reconciliation:run')->dailyAt('01:30')->timezone(BusinessTime::timezone())->onOneServer()->name('daily-reconciliation');

// Review queue: collect new review flags and reconciliation mismatches (idempotent).
Schedule::command('anomalies:collect')->everyFiveMinutes()->withoutOverlapping()->onOneServer()->name('collect-anomalies');

// Report exports: delete files past their retention (records stay).
Schedule::command('reports:prune-exports')->dailyAt('02:00')->timezone(BusinessTime::timezone())->onOneServer()->name('prune-report-exports');

// Shifts open longer than max_open_shift_hours are flagged for supervisors.
Schedule::command('shifts:flag-overdue')->hourly()->onOneServer()->name('flag-overdue-shifts');

// QRIS: ask the provider about open payments (lost notifications, expired QR). Status comes only from the provider.
Schedule::command('payments:check-pending')->everyMinute()->withoutOverlapping()->onOneServer()->name('check-pending-payments');

// Queue failure handling: alert (error log) on failed jobs or a growing backlog.
Schedule::command('ops:queue-health')->everyFiveMinutes()->onOneServer()->name('queue-health');

/*
| Operational commands.
*/

Artisan::command('ops:queue-heartbeat', function () {
    $correlationId = RequestId::generate();
    RequestId::set($correlationId);

    QueueHeartbeat::dispatch(now()->toIso8601String());

    $this->info("Heartbeat dispatched (request_id {$correlationId}). Check the queue worker log.");
})->purpose('Dispatch a no-op job to verify a queue worker is processing jobs');

Artisan::command('ops:queue-health {--window=5} {--max-backlog=500}', function (QueueHealthCheck $check) {
    $result = $check->run((int) $this->option('window'), (int) $this->option('max-backlog'));
    $this->info("Failed jobs in window: {$result['failed_recently']}; backlog: ".($result['backlog'] ?? 'unknown'));

    return $result['failed_recently'] === 0 && $result['backlog'] !== null ? 0 : 1;
})->purpose('Alert on failed queue jobs and queue backlog');
