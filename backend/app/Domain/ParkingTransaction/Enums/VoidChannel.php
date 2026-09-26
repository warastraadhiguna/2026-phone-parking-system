<?php

namespace App\Domain\ParkingTransaction\Enums;

enum VoidChannel: string
{
    /** Requested by the attendant in the app. */
    case MOBILE = 'MOBILE';

    /** Requested by an operator in the Control Center. */
    case ADMIN = 'ADMIN';
}
