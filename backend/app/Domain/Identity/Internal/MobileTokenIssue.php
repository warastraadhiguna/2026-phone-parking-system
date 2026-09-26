<?php

namespace App\Domain\Identity\Internal;

use App\Domain\Identity\Data\MobileTokenPair;
use App\Domain\Identity\Models\MobileRefreshToken;

final class MobileTokenIssue
{
    public function __construct(
        public readonly MobileRefreshToken $refreshToken,
        public readonly MobileTokenPair $pair,
    ) {}
}
