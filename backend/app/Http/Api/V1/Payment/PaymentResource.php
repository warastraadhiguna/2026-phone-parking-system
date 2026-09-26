<?php

namespace App\Http\Api\V1\Payment;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Models\Payment;

final class PaymentResource
{
    /** @return array<string, mixed> */
    public static function make(Payment $p): array
    {
        $showQr = $p->status === PaymentStatus::PENDING;

        return [
            'payment_uuid' => $p->payment_uuid,
            'status' => $p->status->value,
            'method' => $p->payment_method,
            'provider' => $p->provider->value,
            'amount' => $p->amount,
            // The QR is only useful (and only shown) while payment is still possible.
            'qr_string' => $showQr ? $p->qr_string : null,
            'qr_image_url' => $showQr ? $p->qr_image_url : null,
            'expires_at' => $p->expired_at?->toIso8601String(),
            'paid_at' => $p->paid_at?->toIso8601String(),
        ];
    }
}
