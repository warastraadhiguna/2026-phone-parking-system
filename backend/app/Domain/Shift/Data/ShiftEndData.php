<?php

namespace App\Domain\Shift\Data;

use App\Support\Geo\GpsFix;
use Carbon\CarbonImmutable;

final class ShiftEndData
{
    public function __construct(
        public readonly string $shiftUuid,
        public readonly CarbonImmutable $endedAtDevice,
        public readonly GpsFix $gps,
    ) {}

    /** @return array<string, mixed> */
    public function fingerprint(): array
    {
        return [
            'shift_uuid' => $this->shiftUuid,
            'ended_at_device' => $this->endedAtDevice->utc()->format('Y-m-d\TH:i:s\Z'),
            'latitude' => $this->gps->latitude,
            'longitude' => $this->gps->longitude,
            'gps_accuracy_m' => $this->gps->accuracyM,
            'mock_location' => $this->gps->mockLocation,
        ];
    }
}
