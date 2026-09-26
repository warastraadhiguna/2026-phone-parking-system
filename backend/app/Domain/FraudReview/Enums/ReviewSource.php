<?php

namespace App\Domain\FraudReview\Enums;

enum ReviewSource: string
{
    case TRANSACTION = 'TRANSACTION';
    case SHIFT = 'SHIFT';
    case RECONCILIATION = 'RECONCILIATION';

    public function label(): string
    {
        return match ($this) {
            self::TRANSACTION => 'Transaksi',
            self::SHIFT => 'Shift',
            self::RECONCILIATION => 'Rekonsiliasi',
        };
    }
}
