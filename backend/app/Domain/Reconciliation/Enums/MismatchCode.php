<?php

namespace App\Domain\Reconciliation\Enums;

/**
 * Cross-checks between transactions, payments, the cash ledger and settlements (master doc §14).
 *
 * ERROR: the records contradict each other. This should be impossible (DB constraints and
 * single writers), so it is an integrity incident. WARNING: consistent records that need a
 * human decision (e.g. money received for a cancelled transaction).
 */
enum MismatchCode: string
{
    case CASH_WITHOUT_LEDGER = 'CASH_WITHOUT_LEDGER';
    case CASH_LEDGER_AMOUNT = 'CASH_LEDGER_AMOUNT';
    case VOIDED_CASH_NOT_REVERSED = 'VOIDED_CASH_NOT_REVERSED';
    case QRIS_WITHOUT_PAYMENT = 'QRIS_WITHOUT_PAYMENT';
    case QRIS_COMPLETED_UNPAID = 'QRIS_COMPLETED_UNPAID';
    case QRIS_PAYMENT_AMOUNT = 'QRIS_PAYMENT_AMOUNT';
    case SETTLEMENT_LEDGER = 'SETTLEMENT_LEDGER';
    case BALANCE_DRIFT = 'BALANCE_DRIFT';

    case QRIS_PAID_NOT_COMPLETED = 'QRIS_PAID_NOT_COMPLETED';
    case QRIS_PAID_VOIDED_NOT_REFUNDED = 'QRIS_PAID_VOIDED_NOT_REFUNDED';
    case QRIS_STUCK = 'QRIS_STUCK';
    case SETTLEMENT_PENDING = 'SETTLEMENT_PENDING';

    public function severity(): Severity
    {
        return match ($this) {
            self::QRIS_PAID_NOT_COMPLETED, self::QRIS_PAID_VOIDED_NOT_REFUNDED, self::QRIS_STUCK, self::SETTLEMENT_PENDING => Severity::WARNING,
            default => Severity::ERROR,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::CASH_WITHOUT_LEDGER => 'Transaksi tunai tanpa entri buku kas',
            self::CASH_LEDGER_AMOUNT => 'Nominal buku kas ≠ nominal transaksi',
            self::VOIDED_CASH_NOT_REVERSED => 'Transaksi tunai batal tanpa reversal',
            self::QRIS_WITHOUT_PAYMENT => 'Transaksi QRIS tanpa catatan pembayaran',
            self::QRIS_COMPLETED_UNPAID => 'Transaksi QRIS selesai tetapi pembayaran belum lunas',
            self::QRIS_PAYMENT_AMOUNT => 'Nominal pembayaran ≠ nominal transaksi',
            self::SETTLEMENT_LEDGER => 'Setoran terverifikasi tidak sesuai buku kas',
            self::BALANCE_DRIFT => 'Saldo turunan ≠ buku kas',
            self::QRIS_PAID_NOT_COMPLETED => 'QRIS lunas untuk transaksi yang batal (perlu pengembalian/keputusan)',
            self::QRIS_PAID_VOIDED_NOT_REFUNDED => 'QRIS lunas untuk transaksi dibatalkan, belum dikembalikan',
            self::QRIS_STUCK => 'QRIS menunggu pembayaran terlalu lama',
            self::SETTLEMENT_PENDING => 'Setoran menunggu verifikasi lebih dari 24 jam',
        };
    }
}
