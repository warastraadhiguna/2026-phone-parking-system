<?php

namespace App\Support\Health\Checks;

use App\Support\Health\CheckResult;
use App\Support\Health\HealthCheck;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DatabaseCheck implements HealthCheck
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function name(): string
    {
        return 'database';
    }

    public function run(): CheckResult
    {
        $start = hrtime(true);

        try {
            $this->db->connection()->select('select 1');

            return CheckResult::healthy($this->elapsedMs($start));
        } catch (Throwable $e) {
            // Log the class only: connection error messages can contain host/user details.
            Log::warning('Readiness check failed', ['check' => $this->name(), 'exception' => $e::class]);

            return CheckResult::unhealthy($this->elapsedMs($start));
        }
    }

    private function elapsedMs(int $start): float
    {
        return (hrtime(true) - $start) / 1e6;
    }
}
