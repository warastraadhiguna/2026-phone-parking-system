<?php

return [

    /*
    | Mobile (Android) authentication — ADR-0005.
    */
    'mobile' => [
        // Sanctum access token lifetime. Short: a lost device is cut off within this window.
        'access_token_ttl_minutes' => (int) env('IDENTITY_ACCESS_TOKEN_TTL_MINUTES', 60),

        // Refresh token lifetime (sliding: each rotation issues a new token with a fresh expiry).
        'refresh_token_ttl_days' => (int) env('IDENTITY_REFRESH_TOKEN_TTL_DAYS', 30),

        // A refresh token presented again within this window, before its replacement was used,
        // is treated as a retry after a lost response instead of token theft.
        'refresh_reuse_grace_seconds' => (int) env('IDENTITY_REFRESH_REUSE_GRACE_SECONDS', 60),
    ],

    /*
    | Login throttling (attempts per minute).
    */
    'throttle' => [
        'login_per_username' => (int) env('IDENTITY_LOGIN_ATTEMPTS_PER_USERNAME', 5),
        'login_per_ip' => (int) env('IDENTITY_LOGIN_ATTEMPTS_PER_IP', 30),
        'refresh_per_ip' => (int) env('IDENTITY_REFRESH_ATTEMPTS_PER_IP', 30),
    ],

    /*
    | Password for demo accounts created by DatabaseSeeder. Local/testing only; the seeder
    | refuses to run in any other environment.
    */
    'dev_seed_password' => env('DEV_SEED_PASSWORD'),

];
