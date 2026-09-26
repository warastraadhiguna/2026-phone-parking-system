<?php

namespace App\Support\Health;

use Illuminate\Contracts\Container\Container;

/**
 * Runs every readiness check listed in config('health.readiness_checks').
 */
final class ReadinessProbe
{
    public function __construct(private readonly Container $container) {}

    /**
     * @return array{ready: bool, checks: array<string, array{status: string, duration_ms: float}>}
     */
    public function run(): array
    {
        $ready = true;
        $checks = [];

        /** @var list<class-string<HealthCheck>> $classes */
        $classes = config('health.readiness_checks', []);

        foreach ($classes as $class) {
            /** @var HealthCheck $check */
            $check = $this->container->make($class);
            $result = $check->run();

            $checks[$check->name()] = $result->toArray();
            $ready = $ready && $result->healthy;
        }

        return ['ready' => $ready, 'checks' => $checks];
    }
}
