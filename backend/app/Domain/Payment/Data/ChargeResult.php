<?php

namespace App\Domain\Payment\Data;

use Carbon\CarbonImmutable;

/**
 * The provider's answer to a charge. Either accepted (a QR was issued) or rejected. A transport
 * failure is not a result: the adapter throws GatewayUnavailable instead (outcome unknown).
 */
final class ChargeResult
{
    /** @param  array<string, mixed>  $raw  provider payload without secrets */
    private function __construct(
        public readonly bool $accepted,
        public readonly ?string $providerReference,
        public readonly ?string $qrString,
        public readonly ?string $qrImageUrl,
        public readonly ?CarbonImmutable $expiresAt,
        public readonly ?string $rejectReason,
        public readonly array $raw,
    ) {}

    /** @param  array<string, mixed>  $raw */
    public static function accepted(string $providerReference, ?string $qrString, ?string $qrImageUrl, CarbonImmutable $expiresAt, array $raw): self
    {
        return new self(true, $providerReference, $qrString, $qrImageUrl, $expiresAt, null, $raw);
    }

    /** @param  array<string, mixed>  $raw */
    public static function rejected(string $reason, array $raw): self
    {
        return new self(false, null, null, null, null, $reason, $raw);
    }
}
