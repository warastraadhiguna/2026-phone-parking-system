<?php

namespace App\Support\Http;

use App\Support\Errors\ErrorCode;
use App\Support\RequestId\RequestId;
use Illuminate\Http\JsonResponse;

/**
 * The single place that builds API response bodies (master doc §42).
 *
 * Every response has exactly the keys: success, data, meta, error.
 * meta.request_id is always present so clients can quote it in support reports.
 */
final class ApiResponse
{
    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, string>  $headers
     */
    public static function success(mixed $data = null, array $meta = [], int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'data' => $data,
            'meta' => self::meta($meta),
            'error' => null,
        ], $status, $headers);
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers
     */
    public static function error(
        ErrorCode $code,
        ?string $message = null,
        array $details = [],
        ?int $status = null,
        array $headers = [],
    ): JsonResponse {
        $error = [
            'code' => $code->value,
            'message' => $message ?? $code->defaultMessage(),
        ];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return new JsonResponse([
            'success' => false,
            'data' => null,
            'meta' => self::meta([]),
            'error' => $error,
        ], $status ?? $code->httpStatus(), $headers);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private static function meta(array $meta): array
    {
        return ['request_id' => RequestId::current(), ...$meta];
    }
}
