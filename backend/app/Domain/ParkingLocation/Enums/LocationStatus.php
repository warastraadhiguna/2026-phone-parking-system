<?php

namespace App\Domain\ParkingLocation\Enums;

enum LocationStatus: string
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

    /** Only active locations accept new assignments and (later) shifts and transactions. */
    public function isOperational(): bool
    {
        return $this === self::ACTIVE;
    }
}
