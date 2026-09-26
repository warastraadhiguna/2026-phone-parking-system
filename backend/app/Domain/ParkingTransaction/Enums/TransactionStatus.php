<?php

namespace App\Domain\ParkingTransaction\Enums;

/**
 * Master doc §10 / §47. The allowed transitions are also enforced by the database trigger
 * parking_transactions_guard(); keep both in sync.
 *
 *   CASH:  (created) COMPLETED
 *   QRIS:  PENDING → WAITING_PAYMENT → PAID → COMPLETED;  WAITING_PAYMENT → CANCELLED (expired)
 *   Void:  COMPLETED → VOID_REQUESTED → VOIDED | COMPLETED (rejected)
 */
enum TransactionStatus: string
{
    case PENDING = 'PENDING';
    case WAITING_PAYMENT = 'WAITING_PAYMENT';
    case PAID = 'PAID';
    case COMPLETED = 'COMPLETED';
    case VOID_REQUESTED = 'VOID_REQUESTED';
    case VOIDED = 'VOIDED';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Baru',
            self::WAITING_PAYMENT => 'Menunggu pembayaran',
            self::PAID => 'Dibayar',
            self::COMPLETED => 'Selesai',
            self::VOID_REQUESTED => 'Pengajuan batal',
            self::VOIDED => 'Dibatalkan',
            self::CANCELLED => 'Kedaluwarsa/batal',
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, match ($this) {
            self::PENDING => [self::WAITING_PAYMENT, self::COMPLETED],
            self::WAITING_PAYMENT => [self::PAID, self::CANCELLED],
            self::PAID => [self::COMPLETED],
            self::COMPLETED => [self::VOID_REQUESTED],
            self::VOID_REQUESTED => [self::VOIDED, self::COMPLETED],
            self::VOIDED, self::CANCELLED => [],
        }, true);
    }

    /** Counts as revenue (money received and not voided). */
    public function isRevenue(): bool
    {
        return in_array($this, [self::COMPLETED, self::VOID_REQUESTED], true);
    }
}
