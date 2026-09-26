<?php

namespace App\Http\Api\V1\ParkingTransaction;

use App\Domain\ParkingTransaction\Models\ParkingTransaction;

final class TransactionResource
{
    /** @return array<string, mixed> */
    public static function make(ParkingTransaction $t): array
    {
        return [
            'transaction_uuid' => $t->transaction_uuid,
            'transaction_number' => $t->transaction_number,
            'shift_uuid' => $t->shift?->shift_uuid,
            'status' => $t->status->value,
            'payment_method' => $t->payment_method->value,
            'vehicle_type' => $t->vehicle_type->value,
            'vehicle_plate' => $t->vehicle_plate,
            'charged_amount' => $t->charged_tariff_amount,
            'expected_amount' => $t->server_expected_tariff_amount,
            'difference_amount' => $t->tariff_difference_amount,
            'tariff_id' => $t->tariff_id,
            'sync_sequence' => $t->sync_sequence,
            'transaction_time_device' => $t->transaction_time_device->toIso8601String(),
            'transaction_time_server' => $t->transaction_time_server->toIso8601String(),
            'geofence_result' => $t->geofence_result->value,
            'offline_created' => $t->offline_created,
            'review_flags' => $t->review_flags,
        ];
    }
}
