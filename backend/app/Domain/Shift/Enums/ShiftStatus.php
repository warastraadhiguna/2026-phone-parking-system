<?php

namespace App\Domain\Shift\Enums;

/** OPEN ──end──► CLOSED;  OPEN ──supervisor──► FORCED_CLOSED. Closed states are final. */
enum ShiftStatus: string
{
    case OPEN = 'OPEN';
    case CLOSED = 'CLOSED';
    case FORCED_CLOSED = 'FORCED_CLOSED';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Berjalan',
            self::CLOSED => 'Selesai',
            self::FORCED_CLOSED => 'Ditutup paksa',
        };
    }
}
