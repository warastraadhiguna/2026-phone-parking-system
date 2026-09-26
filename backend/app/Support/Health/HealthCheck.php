<?php

namespace App\Support\Health;

/**
 * A dependency that must be reachable before the app can serve traffic (readiness).
 * Implementations must be fast, side-effect free, and must not throw.
 */
interface HealthCheck
{
    /** Stable identifier shown in the readiness response, e.g. "database". */
    public function name(): string;

    public function run(): CheckResult;
}
