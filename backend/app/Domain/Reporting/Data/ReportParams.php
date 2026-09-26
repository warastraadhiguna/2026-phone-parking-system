<?php

namespace App\Domain\Reporting\Data;

use App\Support\Time\BusinessTime;
use Carbon\CarbonImmutable;

/** Report filters. Dates are WIB business dates, inclusive. */
final class ReportParams
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly ?int $locationId = null,
        public readonly ?int $attendantId = null,
        public readonly ?string $paymentMethod = null,
    ) {}

    public function start(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->from, BusinessTime::timezone())->startOfDay()->utc();
    }

    /** Exclusive end. */
    public function end(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->to, BusinessTime::timezone())->startOfDay()->addDay()->utc();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'from' => $this->from,
            'to' => $this->to,
            'location_id' => $this->locationId,
            'attendant_id' => $this->attendantId,
            'payment_method' => $this->paymentMethod,
        ], fn ($v) => $v !== null);
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['from'],
            (string) $data['to'],
            isset($data['location_id']) ? (int) $data['location_id'] : null,
            isset($data['attendant_id']) ? (int) $data['attendant_id'] : null,
            isset($data['payment_method']) ? (string) $data['payment_method'] : null,
        );
    }
}
