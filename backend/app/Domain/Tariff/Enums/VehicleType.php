<?php

namespace App\Domain\Tariff\Enums;

enum VehicleType: string
{
    case MOTORCYCLE = 'MOTORCYCLE';
    case CAR = 'CAR';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::MOTORCYCLE => 'Sepeda Motor',
            self::CAR => 'Mobil',
            self::OTHER => 'Lainnya',
        };
    }
}
