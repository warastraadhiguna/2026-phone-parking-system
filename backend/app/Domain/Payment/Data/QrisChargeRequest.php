<?php

namespace App\Domain\Payment\Data;

use Carbon\CarbonImmutable;

/** What the system asks a provider to charge. Amounts are integer rupiah. */
final class QrisChargeRequest
{
    public function __construct(
        public readonly string $orderId,
        public readonly int $amount,
        public readonly string $itemId,
        public readonly string $itemName,
        public readonly CarbonImmutable $orderTime,
        public readonly int $expiryMinutes,
    ) {}
}
