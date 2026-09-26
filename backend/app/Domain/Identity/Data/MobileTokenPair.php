<?php

namespace App\Domain\Identity\Data;

use Carbon\CarbonImmutable;

/** Plain-text tokens are only ever held here, in memory, to be returned once to the device. */
final class MobileTokenPair
{
    public function __construct(
        public readonly string $accessToken,
        public readonly CarbonImmutable $accessTokenExpiresAt,
        public readonly string $refreshToken,
        public readonly CarbonImmutable $refreshTokenExpiresAt,
    ) {}
}
