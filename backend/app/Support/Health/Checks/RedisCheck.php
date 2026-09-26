<?php

namespace App\Support\Health\Checks;

use App\Support\Health\CheckResult;
use App\Support\Health\HealthCheck;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RedisCheck implements HealthCheck
{
    public function __construct(private readonly RedisManager $redis) {}

    public function name(): string
    {
        return 'redis';
    }

    public function run(): CheckResult
    {
        $start = hrtime(true);

        try {
            $this->redis->connection('health')->command('ping');

            return CheckResult::healthy($this->elapsedMs($start));
        } catch (Throwable $e) {
            Log::warning('Readiness check failed', ['check' => $this->name(), 'exception' => $e::class]);

            return CheckResult::unhealthy($this->elapsedMs($start));
        }
    }

    private function elapsedMs(int $start): float
    {
        return (hrtime(true) - $start) / 1e6;
    }
}
