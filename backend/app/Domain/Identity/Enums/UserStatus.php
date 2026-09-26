<?php

namespace App\Domain\Identity\Enums;

enum UserStatus: string
{
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
    case SUSPENDED = 'SUSPENDED';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Aktif',
            self::INACTIVE => 'Nonaktif',
            self::SUSPENDED => 'Ditangguhkan',
        };
    }

    public function canLogIn(): bool
    {
        return $this === self::ACTIVE;
    }
}
