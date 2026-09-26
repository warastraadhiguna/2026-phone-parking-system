<?php

namespace App\Domain\Identity\Data;

/** What the app tells the server about the installation it runs on. */
final class MobileDeviceInfo
{
    public function __construct(
        public readonly string $uuid,
        public readonly ?string $model = null,
        public readonly ?string $androidVersion = null,
        public readonly ?string $appVersion = null,
    ) {}
}
