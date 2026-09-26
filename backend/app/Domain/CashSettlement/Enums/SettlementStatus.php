<?php

namespace App\Domain\CashSettlement\Enums;

/**
 * Master doc §13. Also enforced by the trigger cash_settlements_guard():
 *   SUBMITTED → VERIFIED (money leaves the attendant's balance) | REJECTED | CANCELLED (by the attendant)
 * A decided settlement never changes again (§46 rule 5).
 */
enum SettlementStatus: string
{
    case SUBMITTED = 'SUBMITTED';
    case VERIFIED = 'VERIFIED';
    case REJECTED = 'REJECTED';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::SUBMITTED => 'Menunggu verifikasi',
            self::VERIFIED => 'Terverifikasi',
            self::REJECTED => 'Ditolak',
            self::CANCELLED => 'Dibatalkan',
        };
    }
}
