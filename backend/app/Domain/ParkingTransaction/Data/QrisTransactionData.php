<?php

namespace App\Domain\ParkingTransaction\Data;

use App\Domain\Tariff\Enums\VehicleType;
use App\Support\Geo\GpsFix;
use Carbon\CarbonImmutable;

/** A QRIS transaction requested by the device. Always online (a provider must issue the QR). */
final class QrisTransactionData
{
    public function __construct(
        public readonly string $transactionUuid,
        public readonly string $shiftUuid,
        public readonly int $syncSequence,
        public readonly VehicleType $vehicleType,
        public readonly ?string $vehiclePlate,
        /** The amount the device shows the customer; must equal the server tariff. */
        public readonly int $chargedAmount,
        public readonly ?int $deviceTariffId,
        public readonly CarbonImmutable $transactionTimeDevice,
        public readonly GpsFix $gps,
    ) {}

    /** @return array<string, mixed> */
    public function fingerprint(): array
    {
        return [
            'payment_method' => 'QRIS',
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
        ];
    }
}
