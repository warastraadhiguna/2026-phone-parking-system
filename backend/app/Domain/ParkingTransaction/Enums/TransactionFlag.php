<?php

namespace App\Domain\ParkingTransaction\Enums;

/** Review signals on a transaction (consumed by the anomaly review in Phase 9). */
enum TransactionFlag: string
{
    /** Charged amount differs from the server's tariff at transaction time (ADR-0008). */
    case TARIFF_MISMATCH = 'TARIFF_MISMATCH';

    /** Offline transaction for which the server knows no applicable tariff. */
    case TARIFF_NOT_FOUND = 'TARIFF_NOT_FOUND';

    case OUTSIDE_GEOFENCE = 'OUTSIDE_GEOFENCE';
    case MOCK_LOCATION = 'MOCK_LOCATION';
    case CLOCK_SKEW = 'CLOCK_SKEW';
    case STALE_OFFLINE = 'STALE_OFFLINE';

    /** Offline transaction whose time lies outside its shift's start/end. */
    case OUTSIDE_SHIFT_WINDOW = 'OUTSIDE_SHIFT_WINDOW';

    /** Offline transaction recorded on a different device than its shift. */
    case DEVICE_MISMATCH = 'DEVICE_MISMATCH';

    /** A QRIS payment was confirmed after its transaction had been cancelled (money arrived late). */
    case LATE_PAYMENT = 'LATE_PAYMENT';

    /** The provider reported another amount than charged; the payment was not accepted. */
    case PAYMENT_AMOUNT_MISMATCH = 'PAYMENT_AMOUNT_MISMATCH';

    /** Too far from the attendant's previous transaction for the time elapsed (MovementCheck). */
    case IMPOSSIBLE_MOVEMENT = 'IMPOSSIBLE_MOVEMENT';

    public function label(): string
    {
        return match ($this) {
            self::TARIFF_MISMATCH => 'Tarif tidak sesuai',
            self::TARIFF_NOT_FOUND => 'Tarif tidak ditemukan',
            self::OUTSIDE_GEOFENCE => 'Di luar geofence',
            self::MOCK_LOCATION => 'Indikasi lokasi palsu',
            self::CLOCK_SKEW => 'Jam perangkat tidak sesuai',
            self::STALE_OFFLINE => 'Data offline terlambat',
            self::OUTSIDE_SHIFT_WINDOW => 'Di luar waktu shift',
            self::DEVICE_MISMATCH => 'Perangkat berbeda dengan shift',
            self::LATE_PAYMENT => 'Pembayaran QRIS terlambat (transaksi sudah batal)',
            self::PAYMENT_AMOUNT_MISMATCH => 'Nominal pembayaran tidak sesuai',
            self::IMPOSSIBLE_MOVEMENT => 'Perpindahan tidak wajar',
        };
    }
}
