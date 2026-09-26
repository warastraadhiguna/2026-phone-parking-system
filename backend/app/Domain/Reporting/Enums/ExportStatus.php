<?php

namespace App\Domain\Reporting\Enums;

enum ExportStatus: string
{
    case QUEUED = 'QUEUED';
    case RUNNING = 'RUNNING';
    case DONE = 'DONE';
    case FAILED = 'FAILED';
    case EXPIRED = 'EXPIRED';

    public function label(): string
    {
        return match ($this) {
            self::QUEUED => 'Menunggu antrean',
            self::RUNNING => 'Diproses',
            self::DONE => 'Siap diunduh',
            self::FAILED => 'Gagal',
            self::EXPIRED => 'Kedaluwarsa',
        };
    }
}
