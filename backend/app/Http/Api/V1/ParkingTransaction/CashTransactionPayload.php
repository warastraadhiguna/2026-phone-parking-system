<?php

namespace App\Http\Api\V1\ParkingTransaction;

use App\Domain\ParkingTransaction\Data\CashTransactionData;
use App\Domain\Tariff\Enums\VehicleType;
use App\Http\Api\V1\DeviceRequest;
use Illuminate\Validation\Rule;

/**
 * Validation and mapping of one cash transaction payload, shared by the single endpoint and the
 * batch sync endpoint. Contract: docs/api/transactions.md.
 */
final class CashTransactionPayload
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalise(array $input): array
    {
        if (isset($input['vehicle_plate']) && $input['vehicle_plate'] !== '') {
            $input['vehicle_plate'] = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $input['vehicle_plate']));
        }

        return $input;
    }

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'transaction_uuid' => ['required', 'uuid'],
            'shift_uuid' => ['required', 'uuid'],
            'sync_sequence' => ['required', 'integer', 'min:1'],
            'vehicle_type' => ['required', Rule::enum(VehicleType::class)],
            'vehicle_plate' => ['nullable', 'string', 'regex:/^[A-Z0-9]{1,15}$/'],
            // QRIS is created through the payment flow (Phase 6).
            'payment_method' => ['required', Rule::in(['CASH'])],
            // Integer rupiah actually charged. The upper bound only catches typing mistakes.
            'charged_amount' => ['required', 'integer', 'between:1,10000000'],
            'tariff_id' => ['nullable', 'integer', 'min:1'],
            'transaction_time_device' => DeviceRequest::DEVICE_TIME,
            'offline_created' => ['sometimes', 'boolean'],
            ...DeviceRequest::gpsRules(),
        ];
    }

    /** @param  array<string, mixed>  $input  validated + normalised */
    public static function toData(array $input): CashTransactionData
    {
        return new CashTransactionData(
            strtolower((string) $input['transaction_uuid']),
            strtolower((string) $input['shift_uuid']),
            (int) $input['sync_sequence'],
            VehicleType::from((string) $input['vehicle_type']),
            isset($input['vehicle_plate']) && $input['vehicle_plate'] !== '' ? (string) $input['vehicle_plate'] : null,
            (int) $input['charged_amount'],
            isset($input['tariff_id']) && $input['tariff_id'] !== '' ? (int) $input['tariff_id'] : null,
            DeviceRequest::timeFrom((string) $input['transaction_time_device']),
            DeviceRequest::gpsFrom($input),
            filter_var($input['offline_created'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );
    }
}
