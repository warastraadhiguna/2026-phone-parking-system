<?php

namespace App\Domain\ParkingLocation\Enums;

/**
 * Location categories. Tariffs are defined per location type (with optional per-location overrides).
 * Dev/default classification pending confirmation against the Perda retribusi parkir.
 */
enum LocationType: string
{
    /** Parkir di tepi jalan umum. */
    case ON_STREET = 'ON_STREET';

    /** Tempat khusus parkir (off-street area / lot). */
    case OFF_STREET = 'OFF_STREET';

    /** Parkir insidentil (event, temporary). */
    case EVENT = 'EVENT';

    public function label(): string
    {
        return match ($this) {
            self::ON_STREET => 'Tepi Jalan Umum',
            self::OFF_STREET => 'Tempat Khusus Parkir',
            self::EVENT => 'Insidentil / Acara',
        };
    }
}
