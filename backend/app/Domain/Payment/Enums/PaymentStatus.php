<?php

namespace App\Domain\Payment\Enums;

/**
 * Payment status (master doc §17; ADR-0006). Set only from verified provider confirmation.
 * The allowed transitions are also enforced by the database trigger payments_guard().
 *
 *   CREATED → PENDING (QR issued) | PAID | EXPIRED | FAILED | CANCELLED
 *   PENDING → PAID | EXPIRED | FAILED | CANCELLED
 *   EXPIRED | FAILED | CANCELLED → PAID   (late confirmation: the money did arrive)
 *   PAID    → nothing. PAID never moves backwards; refunds are payment_adjustments.
 *
 * REFUNDED is listed by the master doc but is never set: a refund is a separate record (Q3).
 */
enum PaymentStatus: string
{
    case CREATED = 'CREATED';
    case PENDING = 'PENDING';
    case PAID = 'PAID';
    case EXPIRED = 'EXPIRED';
    case FAILED = 'FAILED';
    case REFUNDED = 'REFUNDED';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::CREATED => 'Dibuat',
            self::PENDING => 'Menunggu pembayaran',
            self::PAID => 'Lunas',
            self::EXPIRED => 'Kedaluwarsa',
            self::FAILED => 'Gagal',
            self::REFUNDED => 'Dikembalikan',
            self::CANCELLED => 'Dibatalkan',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::CREATED || $this === self::PENDING;
    }

    public function isUnpaidFinal(): bool
    {
        return in_array($this, [self::EXPIRED, self::FAILED, self::CANCELLED], true);
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, match ($this) {
            self::CREATED => [self::PENDING, self::PAID, self::EXPIRED, self::FAILED, self::CANCELLED],
            self::PENDING => [self::PAID, self::EXPIRED, self::FAILED, self::CANCELLED],
            self::EXPIRED, self::FAILED, self::CANCELLED => [self::PAID],
            self::PAID, self::REFUNDED => [],
        }, true);
    }
}
