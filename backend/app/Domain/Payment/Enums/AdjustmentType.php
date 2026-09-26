<?php

namespace App\Domain\Payment\Enums;

enum AdjustmentType: string
{
    /** Money returned to the payer outside the provider (ADR-0006, Q3). */
    case MANUAL_REFUND = 'MANUAL_REFUND';

    public function label(): string
    {
        return match ($this) {
            self::MANUAL_REFUND => 'Pengembalian dana manual',
        };
    }
}
