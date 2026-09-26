<?php

namespace App\Domain\ParkingLocation\Enums;

/** Master doc §24. */
enum GeofenceResult: string
{
    case INSIDE = 'INSIDE';
    case OUTSIDE = 'OUTSIDE';
    case UNKNOWN = 'UNKNOWN';

    public function label(): string
    {
        return match ($this) {
            self::INSIDE => 'Di dalam area',
            self::OUTSIDE => 'Di luar area',
            self::UNKNOWN => 'Tidak diketahui',
        };
    }
}
