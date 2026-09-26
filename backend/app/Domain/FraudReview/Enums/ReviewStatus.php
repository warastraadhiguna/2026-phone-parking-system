<?php

namespace App\Domain\FraudReview\Enums;

/** OPEN → CONFIRMED (a real problem, follow up via void/refund/discipline) | DISMISSED (explained). Final. */
enum ReviewStatus: string
{
    case OPEN = 'OPEN';
    case CONFIRMED = 'CONFIRMED';
    case DISMISSED = 'DISMISSED';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Belum ditinjau',
            self::CONFIRMED => 'Terbukti bermasalah',
            self::DISMISSED => 'Wajar / dijelaskan',
        };
    }
}
