<?php

namespace App\Domain\Payment\Enums;

/** What the system did with a provider answer (payment_provider_events.outcome). */
enum ProviderEventOutcome: string
{
    /** The payment status changed. */
    case APPLIED = 'APPLIED';

    /** Valid answer, but nothing to change (same status, or a backwards move that is refused). */
    case NO_CHANGE = 'NO_CHANGE';

    /** The provider reported another amount than the payment's: nothing is marked PAID. */
    case AMOUNT_MISMATCH = 'AMOUNT_MISMATCH';

    /** A verified notification for an order this system does not know. */
    case UNKNOWN_PAYMENT = 'UNKNOWN_PAYMENT';

    /** The provider refused the charge. */
    case REJECTED = 'REJECTED';
}
