<?php

namespace App\Domain\Assignment\Enums;

/** Display phase of an assignment on a given day. */
enum AssignmentPhase: string
{
    case SCHEDULED = 'SCHEDULED';
    case CURRENT = 'CURRENT';
    case FINISHED = 'FINISHED';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::SCHEDULED => 'Terjadwal',
            self::CURRENT => 'Berjalan',
            self::FINISHED => 'Selesai',
            self::CANCELLED => 'Dibatalkan',
        };
    }
}
