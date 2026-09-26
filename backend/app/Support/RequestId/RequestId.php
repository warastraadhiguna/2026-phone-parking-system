<?php

namespace App\Support\RequestId;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * Correlation ID for one request.
 *
 * Stored in Laravel's Context, so it is automatically added to every log record
 * (as extra.request_id) and carried into queued jobs dispatched during the request.
 */
final class RequestId
{
    public const HEADER = 'X-Request-Id';

    public const CONTEXT_KEY = 'request_id';

    /** Accept only short, log-safe client values; anything else is replaced. */
    private const VALID_PATTERN = '/^[A-Za-z0-9._\-]{8,128}$/';

    /** @phpstan-assert-if-true string $value */
    public static function isValid(?string $value): bool
    {
        return $value !== null && preg_match(self::VALID_PATTERN, $value) === 1;
    }

    public static function generate(): string
    {
        return (string) Str::uuid7();
    }

    public static function set(string $requestId): void
    {
        Context::add(self::CONTEXT_KEY, $requestId);
    }

    public static function current(): ?string
    {
        $value = Context::get(self::CONTEXT_KEY);

        return is_string($value) ? $value : null;
    }
}
