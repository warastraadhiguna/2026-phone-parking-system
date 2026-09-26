<?php

namespace App\Support\Health;

final class CheckResult
{
    private function __construct(
        public readonly bool $healthy,
        public readonly float $durationMs,
    ) {}

    public static function healthy(float $durationMs): self
    {
        return new self(true, $durationMs);
    }

    public static function unhealthy(float $durationMs): self
    {
        return new self(false, $durationMs);
    }

    /** @return array{status: string, duration_ms: float} */
    public function toArray(): array
    {
        return [
            'status' => $this->healthy ? 'ok' : 'fail',
            'duration_ms' => round($this->durationMs, 1),
        ];
    }
}
