<?php

namespace App\Http\Api\V1\Payment;

use App\Domain\ParkingTransaction\Data\QrisTransactionData;
use App\Domain\Tariff\Enums\VehicleType;
use App\Http\Api\V1\DeviceRequest;
use App\Http\Api\V1\ParkingTransaction\CashTransactionPayload;
use Illuminate\Validation\Rule;

/** Contract: docs/api/payments.md. Same fields as a cash transaction, but QRIS and always online. */
final class StoreQrisTransactionRequest extends DeviceRequest
{
    protected function prepareForValidation(): void
    {
        $this->replace(CashTransactionPayload::normalise($this->all()));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'transaction_uuid' => ['required', 'uuid'],
            'shift_uuid' => ['required', 'uuid'],
            'sync_sequence' => ['required', 'integer', 'min:1'],
            'vehicle_type' => ['required', Rule::enum(VehicleType::class)],
            'vehicle_plate' => ['nullable', 'string', 'regex:/^[A-Z0-9]{1,15}$/'],
            'payment_method' => ['required', Rule::in(['QRIS'])],
            'charged_amount' => ['required', 'integer', 'between:1,10000000'],
            'tariff_id' => ['nullable', 'integer', 'min:1'],
            'transaction_time_device' => self::DEVICE_TIME,
            // QRIS needs the provider, so it can never be created offline.
            'offline_created' => ['sometimes', 'boolean', 'declined'],
            ...self::gpsRules(),
        ];
    }

    public function toData(): QrisTransactionData
    {
        $input = $this->all();

        return new QrisTransactionData(
            strtolower((string) $input['transaction_uuid']),
            strtolower((string) $input['shift_uuid']),
            (int) $input['sync_sequence'],
            VehicleType::from((string) $input['vehicle_type']),
            isset($input['vehicle_plate']) && $input['vehicle_plate'] !== '' ? (string) $input['vehicle_plate'] : null,
            (int) $input['charged_amount'],
            isset($input['tariff_id']) && $input['tariff_id'] !== '' ? (int) $input['tariff_id'] : null,
            self::timeFrom((string) $input['transaction_time_device']),
            self::gpsFrom($input),
        );
    }
}
