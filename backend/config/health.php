<?php

use App\Support\Health\Checks\DatabaseCheck;
use App\Support\Health\Checks\RedisCheck;

return [

    /*
    | Dependencies required before this instance may receive traffic.
    | Used by GET /api/v1/health/ready. Liveness (/health/live) never runs these.
    */
    'readiness_checks' => [
        DatabaseCheck::class,
        RedisCheck::class,
    ],

];
