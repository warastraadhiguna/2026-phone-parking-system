<?php

namespace App\Support\Queue;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Queue failure handling (Phase 11). Run every 5 minutes by the scheduler. It raises an error
 * log (the alerting hook) when:
 * - jobs failed since the last window (they stay in failed_jobs for 30 days; retry with
 *   `php artisan queue:retry <uuid>` once the cause is fixed), or
 * - the backlog is above the threshold (workers are down or too few).
 */
final class QueueHealthCheck
{
    /** @return array{failed_recently: int, backlog: int|null} */
    public function run(int $windowMinutes, int $maxBacklog, string $queue = 'default'): array
    {
        $failed = (int) DB::table('failed_jobs')->where('failed_at', '>=', now()->subMinutes($windowMinutes))->count();

        try {
            $backlog = Queue::size($queue);
        } catch (Throwable) {
            $backlog = null;
        }

        if ($failed > 0) {
            Log::error('Queue jobs failed', [
                'failed_last_minutes' => $windowMinutes,
                'count' => $failed,
                'jobs' => DB::table('failed_jobs')->where('failed_at', '>=', now()->subMinutes($windowMinutes))->orderByDesc('id')->limit(5)->pluck('uuid')->all(),
            ]);
        }
        if ($backlog === null) {
            Log::error('Queue backlog could not be read', ['queue' => $queue]);
        } elseif ($backlog > $maxBacklog) {
            Log::error('Queue backlog above threshold', ['queue' => $queue, 'size' => $backlog, 'threshold' => $maxBacklog]);
        }

        return ['failed_recently' => $failed, 'backlog' => $backlog];
    }
}
