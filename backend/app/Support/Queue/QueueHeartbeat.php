<?php

namespace App\Support\Queue;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Operational probe: proves a queue worker is consuming jobs end to end.
 * Dispatched by `php artisan ops:queue-heartbeat`. Has no side effects besides one log line.
 */
class QueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $dispatchedAt) {}

    public function handle(): void
    {
        Log::info('Queue heartbeat processed', [
            'dispatched_at' => $this->dispatchedAt,
            'queue' => $this->job?->getQueue(),
        ]);
    }
}
