<?php

namespace App\Domain\Tariff\Enums;

/**
 * DRAFT ──approve (different user)──► APPROVED (frozen)
 *   └────reject──► REJECTED (final)
 */
enum TariffStatus: string
{
    case DRAFT = 'DRAFT';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::APPROVED => 'Disetujui',
            self::REJECTED => 'Ditolak',
        };
    }
}
