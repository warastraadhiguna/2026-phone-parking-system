<?php

namespace App\Domain\Payment\Data;

use App\Domain\Payment\Enums\GatewayStatus;
use Carbon\CarbonImmutable;

/** A verified, normalised provider status (from a status call, a cancel call or a webhook). */
final class ProviderStatus
{
    /** @param  array<string, mixed>  $raw  provider payload without secrets */
    public function __construct(
        public readonly string $orderId,
        public readonly GatewayStatus $status,
        public readonly ?string $providerReference = null,
        /** Integer rupiah as reported by the provider; null when absent or not a whole amount. */
        public readonly ?int $grossAmount = null,
        public readonly ?CarbonImmutable $paidAt = null,
        public readonly array $raw = [],
        /** Stable key of the notification, used to ignore duplicates (webhooks only). */
        public readonly ?string $eventKey = null,
    ) {}
}
