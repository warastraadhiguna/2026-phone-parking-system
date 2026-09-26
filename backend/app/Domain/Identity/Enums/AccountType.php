<?php

namespace App\Domain\Identity\Enums;

/**
 * Which channel an account may use. Enforced at login and on every request.
 */
enum AccountType: string
{
    /** Dishub / finance / supervisor / auditor / executive staff: admin web only. */
    case STAFF = 'STAFF';

    /** Juru parkir: Android app only. */
    case ATTENDANT = 'ATTENDANT';

    public function label(): string
    {
        return match ($this) {
            self::STAFF => 'Staf',
            self::ATTENDANT => 'Juru Parkir',
        };
    }
}
