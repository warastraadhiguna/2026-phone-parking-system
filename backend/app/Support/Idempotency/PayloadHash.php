<?php

namespace App\Support\Idempotency;

/**
 * Fingerprint of a client-submitted payload, used to tell a harmless retry (same UUID, same
 * payload) from a conflict (same UUID, different payload → SYNC_CONFLICT).
 */
final class PayloadHash
{
    /** @param  array<string, mixed>  $payload */
    public static function of(array $payload): string
    {
        return hash('sha256', (string) json_encode(self::normalise($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function normalise(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map(self::normalise(...), $value);
        }

        return $value;
    }
}
