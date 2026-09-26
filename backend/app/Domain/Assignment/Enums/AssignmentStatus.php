<?php

namespace App\Domain\Assignment\Enums;

/**
 * Stored status. Whether an ACTIVE assignment is scheduled, current or finished follows from
 * its dates (see AssignmentPhase).
 */
enum AssignmentStatus: string
{
    case ACTIVE = 'ACTIVE';

    /** Withdrawn before it started (kept for history, never deleted). */
    case CANCELLED = 'CANCELLED';
}
