<?php

namespace App\Domain\Shift\Data;

use App\Support\Geo\GpsFix;
use Carbon\CarbonImmutable;

final class ShiftStartData
{
    public function __construct(
        public readonly string $shiftUuid,
        public readonly int $locationId,
        public readonly CarbonImmutable $startedAtDevice,
        public readonly GpsFix $gps,
        public readonly bool $offlineCreated,
    ) {}

    /** @return array<string, mixed> what the client sent, for idempotency fingerprints */
    public function fingerprint(): array
    {
        return [
            'shift_uuid' => $this->shiftUuid,
            'location_id' => $this->locationId,
            'started_at_device' => $this->startedAtDevice->utc()->format('Y-m-d\TH:i:s\Z'),
            'latitude' => $this->gps->latitude,
            'longitude' => $this->gps->longitude,
            'gps_accuracy_m' => $this->gps->accuracyM,
            'mock_location' => $this->gps->mockLocation,
            'offline_created' => $this->offlineCreated,
        ];
    }
}
