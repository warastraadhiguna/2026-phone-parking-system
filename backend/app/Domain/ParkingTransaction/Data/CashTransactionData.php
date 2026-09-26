<?php

namespace App\Domain\ParkingTransaction\Data;

use App\Domain\Tariff\Enums\VehicleType;
use App\Support\Geo\GpsFix;
use Carbon\CarbonImmutable;

/** A cash transaction as recorded by the device (online, or offline and synced later). */
final class CashTransactionData
{
    public function __construct(
        public readonly string $transactionUuid,
        public readonly string $shiftUuid,
        public readonly int $syncSequence,
        public readonly VehicleType $vehicleType,
        public readonly ?string $vehiclePlate,
        public readonly int $chargedAmount,
        public readonly ?int $deviceTariffId,
        public readonly CarbonImmutable $transactionTimeDevice,
        public readonly GpsFix $gps,
        public readonly bool $offlineCreated,
    ) {}

    /** @return array<string, mixed> */
    public function fingerprint(): array
    {
        return [
            'transaction_uuid' => $this->transactionUuid,
            'shift_uuid' => $this->shiftUuid,
            'sync_sequence' => $this->syncSequence,
            'vehicle_type' => $this->vehicleType->value,
            'vehicle_plate' => $this->vehiclePlate,
            'charged_amount' => $this->chargedAmount,
            'tariff_id' => $this->deviceTariffId,
            'transaction_time_device' => $this->transactionTimeDevice->utc()->format('Y-m-d\TH:i:s\Z'),
            'latitude' => $this->gps->latitude,
            'longitude' => $this->gps->longitude,
            'gps_accuracy_m' => $this->gps->accuracyM,
            'mock_location' => $this->gps->mockLocation,
            'offline_created' => $this->offlineCreated,
        ];
    }
}
