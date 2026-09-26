<?php

namespace App\Domain\Shift\Enums;

/**
 * Review signals attached to a shift. They never block an offline shift that already happened;
 * the anomaly review queue (Phase 9) consumes them.
 */
enum ShiftFlag: string
{
    /** Offline shift started on a day without an assignment. */
    case NO_ASSIGNMENT = 'NO_ASSIGNMENT';

    /** Offline shift started at a location other than the assigned one. */
    case LOCATION_MISMATCH = 'LOCATION_MISMATCH';

    /** Offline shift started at a location that is not ACTIVE. */
    case LOCATION_INACTIVE = 'LOCATION_INACTIVE';

    /** Offline shift started before the device was approved. */
    case DEVICE_NOT_APPROVED_AT_START = 'DEVICE_NOT_APPROVED_AT_START';

    case OUTSIDE_GEOFENCE_START = 'OUTSIDE_GEOFENCE_START';
    case OUTSIDE_GEOFENCE_END = 'OUTSIDE_GEOFENCE_END';
    case MOCK_LOCATION = 'MOCK_LOCATION';

    /** Device and server clocks disagree by more than max_clock_skew_minutes. */
    case CLOCK_SKEW = 'CLOCK_SKEW';

    /** Offline data synced later than offline_transaction_warning_hours. */
    case STALE_OFFLINE = 'STALE_OFFLINE';

    /** Still open after max_open_shift_hours. */
    case OVERDUE = 'OVERDUE';

    public function label(): string
    {
        return match ($this) {
            self::NO_ASSIGNMENT => 'Tanpa penugasan',
            self::LOCATION_MISMATCH => 'Lokasi tidak sesuai penugasan',
            self::LOCATION_INACTIVE => 'Lokasi tidak aktif',
            self::DEVICE_NOT_APPROVED_AT_START => 'Perangkat belum disetujui saat mulai',
            self::OUTSIDE_GEOFENCE_START => 'Mulai di luar geofence',
            self::OUTSIDE_GEOFENCE_END => 'Selesai di luar geofence',
            self::MOCK_LOCATION => 'Indikasi lokasi palsu',
            self::CLOCK_SKEW => 'Jam perangkat tidak sesuai',
            self::STALE_OFFLINE => 'Data offline terlambat',
            self::OVERDUE => 'Melebihi batas waktu shift',
        };
    }
}
