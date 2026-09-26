<?php

namespace App\Support\Security;

use RuntimeException;

/**
 * Refuses to boot a production application with settings that would expose it or put data in
 * the wrong place (master doc §33, §54): debug output on, a non-HTTPS URL, or a database other
 * than PostgreSQL (ADR-0002). Checked at boot, like the payment guard.
 */
final class ProductionGuard
{
    public static function assertSafe(string $environment, bool $debug, string $appUrl, string $dbConnection = 'pgsql'): void
    {
        if ($environment !== 'production') {
            return;
        }
        if ($debug) {
            throw new RuntimeException('APP_DEBUG must be false in production.');
        }
        if (! str_starts_with(strtolower($appUrl), 'https://')) {
            throw new RuntimeException('APP_URL must use https:// in production.');
        }
        if ($dbConnection !== 'pgsql') {
            throw new RuntimeException('DB_CONNECTION must be pgsql in production.');
        }
    }
}
