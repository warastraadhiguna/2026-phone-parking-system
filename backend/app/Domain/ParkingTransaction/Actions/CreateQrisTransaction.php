<?php

namespace App\Domain\ParkingTransaction\Actions;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Device\Models\Device;
use App\Domain\Identity\Models\User;
use App\Domain\ParkingAttendant\Models\ParkingAttendant;
use App\Domain\ParkingLocation\Enums\GeofenceResult;
use App\Domain\ParkingLocation\Models\ParkingLocation;
use App\Domain\ParkingLocation\Services\Geofence;
use App\Domain\ParkingTransaction\Data\QrisOutcome;
use App\Domain\ParkingTransaction\Data\QrisTransactionData;
use App\Domain\ParkingTransaction\Enums\PaymentMethod;
use App\Domain\ParkingTransaction\Enums\TransactionFlag;
use App\Domain\ParkingTransaction\Enums\TransactionStatus;
use App\Domain\ParkingTransaction\Internal\TransactionNumberGenerator;
use App\Domain\ParkingTransaction\Models\ParkingTransaction;
use App\Domain\ParkingTransaction\Services\MovementCheck;
use App\Domain\Payment\Actions\ChargePayment;
use App\Domain\Payment\Actions\CreatePayment;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Exceptions\GatewayUnavailable;
use App\Domain\Payment\Models\Payment;
use App\Domain\Shift\Enums\ShiftStatus;
use App\Domain\Shift\Models\Shift;
use App\Domain\SystemConfiguration\Enums\SettingKey;
use App\Domain\SystemConfiguration\Services\DeviceTimePolicy;
use App\Domain\SystemConfiguration\Services\Settings;
use App\Domain\Tariff\Services\TariffResolver;
use App\Support\Errors\ApiException;
use App\Support\Errors\ErrorCode;
use App\Support\Idempotency\PayloadHash;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Starts a QRIS parking transaction (master doc §16, Scenario B):
 *   1. one DB transaction: transaction WAITING_PAYMENT + payment CREATED + audit;
 *   2. outside it: the provider issues the dynamic QR (ChargePayment).
 * The transaction becomes COMPLETED only when the provider confirms payment (webhook or status
 * check); the device never decides. Idempotent on transaction_uuid: a retry returns the same
 * transaction and QR.
 *
 * Online only: open shift on this device, and the amount must equal the server tariff
 * (the QR charges exactly what the server says; otherwise TARIFF_CHANGED).
 */
final class CreateQrisTransaction
{
    public function __construct(
        private readonly TariffResolver $tariffs,
        private readonly Geofence $geofence,
        private readonly Settings $settings,
        private readonly DeviceTimePolicy $time,
        private readonly TransactionNumberGenerator $numbers,
        private readonly CreatePayment $createPayment,
        private readonly ChargePayment $charge,
        private readonly RecordAuditEvent $audit,
        private readonly MovementCheck $movement,
    ) {}

    /** @throws GatewayUnavailable when the provider's answer is unknown; retry with the same UUID */
    public function handle(User $user, ParkingAttendant $attendant, Device $device, QrisTransactionData $data): QrisOutcome
    {
        $hash = PayloadHash::of($data->fingerprint());

        try {
            $outcome = DB::transaction(fn () => $this->record($user, $attendant, $device, $data, $hash));
        } catch (QueryException $e) {
            if ($e->getCode() !== '23505') {
                throw $e;
            }
            $outcome = DB::transaction(fn () => $this->replay($attendant, $data, $hash)
                ?? throw new ApiException(ErrorCode::SYNC_CONFLICT, 'sync_sequence sudah dipakai oleh transaksi lain.'));
        }

        $payment = $outcome->payment;
        if ($payment->status === PaymentStatus::CREATED) {
            $payment = $this->charge->handle($payment);
        }

        return new QrisOutcome($outcome->transaction->refresh(), $payment, $outcome->created);
    }

    private function record(User $user, ParkingAttendant $attendant, Device $device, QrisTransactionData $data, string $hash): QrisOutcome
    {
        $existing = $this->replay($attendant, $data, $hash);
        if ($existing !== null) {
            return $existing;
        }

        if (ParkingTransaction::query()->where('device_id', $device->id)->where('sync_sequence', $data->syncSequence)->exists()) {
            throw new ApiException(ErrorCode::SYNC_CONFLICT, 'sync_sequence sudah dipakai oleh transaksi lain.', ['sync_sequence' => $data->syncSequence]);
        }

        $shift = Shift::query()->where('shift_uuid', $data->shiftUuid)->where('attendant_id', $attendant->id)->first();
        if ($shift === null || $shift->status !== ShiftStatus::OPEN) {
            throw new ApiException(ErrorCode::SHIFT_NOT_ACTIVE, 'QRIS memerlukan shift yang sedang berjalan dan sudah tersinkron.', ['shift_uuid' => $data->shiftUuid]);
        }
        if ($shift->device_id !== $device->id) {
            throw new ApiException(ErrorCode::DEVICE_NOT_ALLOWED, 'Shift ini dibuka dari perangkat lain.');
        }

        /** @var ParkingLocation $location */
        $location = $shift->location;
        $now = CarbonImmutable::now();
        $tariff = $this->tariffs->find($data->vehicleType, $location, $now)
            ?? throw new ApiException(ErrorCode::TARIFF_NOT_FOUND, "Tidak ada tarif berlaku untuk {$data->vehicleType->label()} di lokasi ini.");

        if ($tariff->amount !== $data->chargedAmount) {
            throw new ApiException(ErrorCode::TARIFF_CHANGED, 'Tarif sudah berubah. Muat ulang data lalu coba lagi.', [
                'expected_amount' => $tariff->amount,
                'tariff_id' => $tariff->id,
            ]);
        }

        $flags = [];
        if ($this->time->assess($data->transactionTimeDevice, false, $now)['clock_skew']) {
            $flags[] = TransactionFlag::CLOCK_SKEW;
        }
        $check = $this->geofence->check($location, $data->gps, $this->settings->int(SettingKey::GPS_MAX_ACCURACY_M));
        if ($check->result === GeofenceResult::OUTSIDE) {
            $flags[] = TransactionFlag::OUTSIDE_GEOFENCE;
        }
        if ($data->gps->mockLocation) {
            $flags[] = TransactionFlag::MOCK_LOCATION;
        }
        if ($this->movement->isImpossible($attendant->id, $data->gps, $data->transactionTimeDevice)) {
            $flags[] = TransactionFlag::IMPOSSIBLE_MOVEMENT;
        }

        $transaction = (new ParkingTransaction([
            'transaction_uuid' => $data->transactionUuid,
            'transaction_number' => $this->numbers->next($now),
            'shift_id' => $shift->id,
            'attendant_id' => $attendant->id,
            'location_id' => $location->id,
            'device_id' => $device->id,
            'sync_sequence' => $data->syncSequence,
            'vehicle_type' => $data->vehicleType,
            'vehicle_plate' => $data->vehiclePlate,
            'tariff_id' => $tariff->id,
            'device_tariff_id' => $data->deviceTariffId,
            'charged_tariff_amount' => $tariff->amount,
            'server_expected_tariff_amount' => $tariff->amount,
            'tariff_difference_amount' => 0,
            'payment_method' => PaymentMethod::QRIS,
            'status' => TransactionStatus::WAITING_PAYMENT,
            'transaction_time_device' => $data->transactionTimeDevice,
            'transaction_time_server' => $now,
            'latitude' => $data->gps->latitude,
            'longitude' => $data->gps->longitude,
            'gps_accuracy_m' => $data->gps->accuracyM,
            'mock_location' => $data->gps->mockLocation,
            'geofence_result' => $check->result,
            'distance_m' => $check->distanceM,
            'offline_created' => false,
            'review_flags' => [],
            'payload_hash' => $hash,
        ]))->withFlags($flags);
        $transaction->save();

        $payment = $this->createPayment->handle($transaction, $user, $device->device_uuid);

        $this->audit->handle(AuditAction::CREATE_TRANSACTION, $user, 'parking_transaction', $transaction->transaction_uuid, [
            'transaction_number' => $transaction->transaction_number,
            'payment_method' => PaymentMethod::QRIS->value,
            'vehicle_type' => $data->vehicleType->value,
            'charged_amount' => $tariff->amount,
            'tariff_id' => $tariff->id,
            'payment_uuid' => $payment->payment_uuid,
            'flags' => $transaction->review_flags,
        ], deviceUuid: $device->device_uuid);

        return new QrisOutcome($transaction, $payment, created: true);
    }

    private function replay(ParkingAttendant $attendant, QrisTransactionData $data, string $hash): ?QrisOutcome
    {
        $existing = ParkingTransaction::query()->where('transaction_uuid', $data->transactionUuid)->first();
        if ($existing === null) {
            return null;
        }

        if ($existing->attendant_id !== $attendant->id || ! hash_equals($existing->payload_hash, $hash)) {
            throw new ApiException(ErrorCode::SYNC_CONFLICT, 'Transaksi dengan UUID ini sudah ada dengan data berbeda.', ['transaction_uuid' => $data->transactionUuid]);
        }

        $payment = Payment::query()->where('transaction_id', $existing->id)->firstOrFail();

        return new QrisOutcome($existing, $payment, created: false);
    }
}
