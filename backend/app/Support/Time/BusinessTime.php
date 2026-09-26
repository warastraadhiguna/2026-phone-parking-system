<?php

namespace App\Support\Time;

use Carbon\CarbonImmutable;

/**
 * Calendar days are WIB (config app.business_timezone); storage is UTC.
 */
final class BusinessTime
{
    public static function timezone(): string
    {
        return (string) config('app.business_timezone');
    }

    /** Today's date (Y-m-d) in the business timezone. */
    public static function today(): string
    {
        return CarbonImmutable::now(self::timezone())->toDateString();
    }

    /** Interprets a local (WIB) date-time string and returns it in UTC. */
    public static function fromLocal(string $localDateTime): CarbonImmutable
    {
        return CarbonImmutable::parse($localDateTime, self::timezone())->utc();
    }

    public static function toLocalInput(?CarbonImmutable $utc): ?string
    {
        return $utc?->setTimezone(self::timezone())->format('Y-m-d\TH:i');
    }
}
