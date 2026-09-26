<?php

namespace App\Domain\Identity\Data;

use App\Domain\Identity\Models\User;

final class MobileLogin
{
    /**
     * @param  array<string, mixed>  $device  summary from MobileDeviceGate::admit()
     */
    public function __construct(
        public readonly User $user,
        public readonly MobileTokenPair $tokens,
        public readonly array $device = [],
    ) {}
}
