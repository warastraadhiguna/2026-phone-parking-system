<?php

namespace App\Domain\Identity\Data;

/**
 * Naming convention of mobile Sanctum access tokens: "mobile:<device_uuid>", ability "mobile".
 */
final class MobileTokenName
{
    public const ABILITY = 'mobile';

    private const PREFIX = 'mobile:';

    public static function for(string $deviceUuid): string
    {
        return self::PREFIX.$deviceUuid;
    }

    public static function likePattern(?string $deviceUuid = null): string
    {
        return self::PREFIX.($deviceUuid ?? '').'%';
    }

    public static function deviceUuid(string $tokenName): ?string
    {
        return str_starts_with($tokenName, self::PREFIX) ? substr($tokenName, strlen(self::PREFIX)) : null;
    }
}
