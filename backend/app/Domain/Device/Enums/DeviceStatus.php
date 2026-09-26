<?php

namespace App\Domain\Device\Enums;

/**
 * Device lifecycle (ADR-0005):
 *
 *   PENDING_APPROVAL ──approve──► ACTIVE ──revoke──► REVOKED
 *          │                        └────lost────► LOST
 *          └──reject (revoke)──► REVOKED
 *
 * REVOKED and LOST are final.
 */
enum DeviceStatus: string
{
    case PENDING_APPROVAL = 'PENDING_APPROVAL';
    case ACTIVE = 'ACTIVE';
    case REVOKED = 'REVOKED';
    case LOST = 'LOST';

    public function label(): string
    {
        return match ($this) {
            self::PENDING_APPROVAL => 'Menunggu Persetujuan',
            self::ACTIVE => 'Aktif',
            self::REVOKED => 'Dicabut',
            self::LOST => 'Hilang',
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::PENDING_APPROVAL => in_array($to, [self::ACTIVE, self::REVOKED], true),
            self::ACTIVE => in_array($to, [self::REVOKED, self::LOST], true),
            self::REVOKED, self::LOST => false,
        };
    }

    public function isFinal(): bool
    {
        return $this === self::REVOKED || $this === self::LOST;
    }

    /** Only an ACTIVE device may start shifts, create transactions or sync operational data. */
    public function isOperational(): bool
    {
        return $this === self::ACTIVE;
    }
}
