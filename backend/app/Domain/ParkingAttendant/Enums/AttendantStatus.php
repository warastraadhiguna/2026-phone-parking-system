<?php

namespace App\Domain\ParkingAttendant\Enums;

use App\Domain\Identity\Enums\UserStatus;

enum AttendantStatus: string
{
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
    case SUSPENDED = 'SUSPENDED';

    /** Registration validity (expired_at) has passed. Set by the daily expiry job. */
    case EXPIRED = 'EXPIRED';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Aktif',
            self::INACTIVE => 'Nonaktif',
            self::SUSPENDED => 'Ditangguhkan',
            self::EXPIRED => 'Kedaluwarsa',
        };
    }

    public function isOperational(): bool
    {
        return $this === self::ACTIVE;
    }

    /** The attendant's login account mirrors the attendant status. */
    public function userStatus(): UserStatus
    {
        return match ($this) {
            self::ACTIVE => UserStatus::ACTIVE,
            self::SUSPENDED => UserStatus::SUSPENDED,
            self::INACTIVE, self::EXPIRED => UserStatus::INACTIVE,
        };
    }
}
