<?php

return [

    /*
    | Active payment gateway (ADR-0006): "midtrans" or "fake".
    |
    | "fake" is for local development and tests only. The application refuses to boot in
    | production with it (PaymentGatewayResolver::assertSafeConfiguration).
    */
    'gateway' => env('PAYMENT_GATEWAY', 'fake'),

    /*
    | Midtrans Core API (QRIS). Credentials come only from the environment; nothing is committed.
    | Docs checked 2026-09-25: POST /v2/charge, GET /v2/{order_id}/status, POST /v2/{order_id}/cancel,
    | Basic auth "server_key:", webhook signature SHA512(order_id + status_code + gross_amount + server_key).
    */
    'midtrans' => [
        // "sandbox" until production is explicitly approved (master doc §54).
        'environment' => env('MIDTRANS_ENVIRONMENT', 'sandbox'),

        // Must be set to true, in production only, to allow the production environment.
        'production_approved' => (bool) env('MIDTRANS_PRODUCTION_APPROVED', false),

        'server_key' => env('MIDTRANS_SERVER_KEY'),

        'base_urls' => [
            'sandbox' => env('MIDTRANS_SANDBOX_BASE_URL', 'https://api.sandbox.midtrans.com'),
            'production' => env('MIDTRANS_PRODUCTION_BASE_URL', 'https://api.midtrans.com'),
        ],

        // QRIS acquirer sent with each charge ("gopay" or "airpay shopee"), per the merchant contract.
        'qris_acquirer' => env('MIDTRANS_QRIS_ACQUIRER', 'gopay'),

        'timeout_seconds' => (int) env('MIDTRANS_TIMEOUT_SECONDS', 10),
    ],

    // Webhook requests accepted per minute per source IP.
    'webhook_per_minute' => (int) env('PAYMENT_WEBHOOK_PER_MINUTE', 300),

    /*
    | Fake gateway (local/tests). Its webhook signing key is derived from APP_KEY when not set.
    */
    'fake' => [
        'signing_key' => env('FAKE_PAYMENT_SIGNING_KEY'),
    ],
];
