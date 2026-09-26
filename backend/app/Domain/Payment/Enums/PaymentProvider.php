<?php

namespace App\Domain\Payment\Enums;

enum PaymentProvider: string
{
    case MIDTRANS = 'MIDTRANS';

    /** Local development and tests only; never active in production (ADR-0006). */
    case FAKE = 'FAKE';

    /** The name used in the webhook URL: /api/v1/payments/webhooks/{name}. */
    public function slug(): string
    {
        return strtolower($this->value);
    }
}
