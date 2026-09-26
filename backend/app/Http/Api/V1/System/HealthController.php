<?php

namespace App\Http\Api\V1\System;

use App\Support\Errors\ErrorCode;
use App\Support\Health\ReadinessProbe;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Semantics are documented in docs/architecture/observability.md.
 */
final class HealthController
{
    /** The PHP process is up and can route a request. Touches no external dependency. */
    public function live(): JsonResponse
    {
        return ApiResponse::success(['status' => 'alive'], headers: ['Cache-Control' => 'no-store']);
    }

    /** Every required dependency is reachable; 503 otherwise. */
    public function ready(ReadinessProbe $probe): JsonResponse
    {
        $result = $probe->run();

        if (! $result['ready']) {
            return ApiResponse::error(
                ErrorCode::SERVICE_UNAVAILABLE,
                'One or more required dependencies are unavailable.',
                ['checks' => $result['checks']],
                headers: ['Cache-Control' => 'no-store'],
            );
        }

        return ApiResponse::success(
            ['status' => 'ready', 'checks' => $result['checks']],
            headers: ['Cache-Control' => 'no-store'],
        );
    }
}
