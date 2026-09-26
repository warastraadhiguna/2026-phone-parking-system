<?php

namespace App\Domain\Payment\Enums;

/** A provider's answer, normalised by the gateway adapter. */
enum GatewayStatus: string
{
    case PENDING = 'PENDING';
    case PAID = 'PAID';
    case EXPIRED = 'EXPIRED';
    case FAILED = 'FAILED';
    case CANCELLED = 'CANCELLED';

    /** Refunded at the provider. Never moves a PAID payment backwards; recorded for review. */
    case REFUNDED = 'REFUNDED';

    /** The provider does not know this order (e.g. the charge never reached it). */
    case NOT_FOUND = 'NOT_FOUND';

    case UNKNOWN = 'UNKNOWN';

    public function paymentStatus(): ?PaymentStatus
    {
        return match ($this) {
            self::PENDING => PaymentStatus::PENDING,
            self::PAID => PaymentStatus::PAID,
            self::EXPIRED => PaymentStatus::EXPIRED,
            self::FAILED => PaymentStatus::FAILED,
            self::CANCELLED => PaymentStatus::CANCELLED,
            self::REFUNDED, self::NOT_FOUND, self::UNKNOWN => null,
        };
    }
}
