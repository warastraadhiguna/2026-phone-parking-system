<?php

namespace App\Domain\ParkingTransaction\Enums;

enum PaymentMethod: string
{
    case CASH = 'CASH';
    case QRIS = 'QRIS';

    public function label(): string
    {
        return match ($this) {
            self::CASH => 'Tunai',
            self::QRIS => 'QRIS',
        };
    }
}
