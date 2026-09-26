<?php

namespace App\Domain\Audit\Enums;

enum ActorType: string
{
    /** An authenticated user (actor_id set). */
    case USER = 'USER';

    /** The system itself: scheduled jobs, console commands, automatic rules. */
    case SYSTEM = 'SYSTEM';

    /** An unauthenticated request, e.g. a failed login. */
    case ANONYMOUS = 'ANONYMOUS';
}
