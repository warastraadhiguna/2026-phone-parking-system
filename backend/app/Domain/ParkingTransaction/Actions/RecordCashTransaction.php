<?php

namespace App\Domain\ParkingTransaction\Actions;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\CashLedger\Actions\RecordCashIn;
use App\Domain\Device\Models\Device;
use App\Domain\Identity\Models\User;
use App\Domain\ParkingAttendant\Models\ParkingAttendant;
use App\Domain\ParkingLocation\Enums\GeofenceResult;
use App\Domain\ParkingLocation\Models\ParkingLocation;
use App\Domain\ParkingLocation\Services\Geofence;
use App\Domain\ParkingTransaction\Data\CashTransactionData;
use App\Domain\ParkingTransaction\Data\TransactionOutcome;
use App\Domain\ParkingTransaction\Enums\PaymentMethod;
use App\Domain\ParkingTransaction\Enums\TransactionFlag;
use App\Domain\ParkingTransaction\Enums\TransactionStatus;
use App\Domain\ParkingTransaction\Internal\TransactionNumberGenerator;
use App\Domain\ParkingTransaction\Models\ParkingTransaction;
use App\Domain\ParkingTransaction\Services\MovementCheck;
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
 * Records a CASH parking transaction and its ledger cash-in in one DB transaction
 * (master doc §10–§11, §21, §46; ADR-0008).
 *
 * - Idempotent on transaction_uuid (and unique per device + sync_sequence).
 * - The charged amount is what really happened in the field and is never replaced. The server's
 *   applicable tariff is stored beside it; a difference raises TARIFF_MISMATCH for review.
 * - Online: the shift must be open and belong to this device, and a tariff must exist.
 *   Offline: the record is accepted and deviations become review flags.
 */
final class RecordCashTransaction
{
    public function __construct(
        private readonly TariffResolver $tariffs,
        private readonly Geofence $geofence,
        private readonly Settings $settings,
        private readonly DeviceTimePolicy $time,
        private readonly TransactionNumberGenerator $numbers,
        private readonly RecordCashIn $cashIn,
        private readonly RecordAuditEvent $audit,
        private readonly MovementCheck $movement,
    ) {}

    public function handle(User $user, ParkingAttendant $attendant, Device $device, CashTransactionData $data): TransactionOutcome
    {
        $hash = PayloadHash::of($data->fingerprint());

        try {
            return DB::transaction(fn () => $this->record($user, $attendant, $device, $data, $hash));
        } catch (QueryException $e) {
            // A concurrent duplicate lost the race on a unique index: treat like any duplicate.
            if ($e->getCode() === '23505') {
                return DB::transaction(fn () => $this->replay($attendant, $data, $hash)
                    ?? throw new ApiException(ErrorCode::SYNC_CONFLICT, 'sync_sequence sudah dipakai oleh transaksi lain.'));
            }
            throw $e;
        }
    }

    private function record(User $user, ParkingAttendant $attendant, Device $device, CashTransactionData $data, string $hash): TransactionOutcome
    {
        $existing = $this->replay($attendant, $data, $hash);
        if ($existing !== null) {
            return $existing;
        }

        if (ParkingTransaction::query()->where('device_id', $device->id)->where('sync_sequence', $data->syncSequence)->exists()) {
            throw new ApiException(ErrorCode::SYNC_CONFLICT, 'sync_sequence sudah dipakai oleh transaksi lain.', ['sync_sequence' => $data->syncSequence]);
        }

        $shift = Shift::query()->where('shift_uuid', $data->shiftUuid)->where('attendant_id', $attendant->id)->first()
            ?? throw new ApiException(ErrorCode::SHIFT_NOT_ACTIVE, 'Shift tidak ditemukan. Sinkronkan shift terlebih dahulu.', ['shift_uuid' => $data->shiftUuid]);

        $now = CarbonImmutable::now();
        $flags = [];

        if (! $data->offlineCreated) {
            if ($shift->status !== ShiftStatus::OPEN) {
                throw new ApiException(ErrorCode::SHIFT_NOT_ACTIVE);
            }
            if ($shift->device_id !== $device->id) {
                throw new ApiException(ErrorCode::DEVICE_NOT_ALLOWED, 'Shift ini dibuka dari perangkat lain.');
            }
        } else {
            $endsAt = $shift->ended_at_device ?? $shift->ended_at_server;
            if ($data->transactionTimeDevice->lessThan($shift->started_at_device) || ($endsAt !== null && $data->transactionTimeDevice->greaterThan($endsAt))) {
                $flags[] = TransactionFlag::OUTSIDE_SHIFT_WINDOW;
            }
            if ($shift->device_id !== $device->id) {
                $flags[] = TransactionFlag::DEVICE_MISMATCH;
            }
        }

        /** @var ParkingLocation $location */
        $location = $shift->location;
        $pricedAt = $data->offlineCreated ? $data->transactionTimeDevice : $now;
        $tariff = $this->tariffs->find($data->vehicleType, $location, $pricedAt);

        if ($tariff === null) {
            if (! $data->offlineCreated) {
                throw new ApiException(ErrorCode::TARIFF_NOT_FOUND, "Tidak ada tarif berlaku untuk {$data->vehicleType->label()} di lokasi ini.");
            }
            $flags[] = TransactionFlag::TARIFF_NOT_FOUND;
        } elseif ($tariff->amount !== $data->chargedAmount) {
            $flags[] = TransactionFlag::TARIFF_MISMATCH;
        }

        $timing = $this->time->assess($data->transactionTimeDevice, $data->offlineCreated, $now);
        if ($timing['clock_skew']) {
            $flags[] = TransactionFlag::CLOCK_SKEW;
        }
        if ($timing['stale']) {
            $flags[] = TransactionFlag::STALE_OFFLINE;
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
            'transaction_number' => $this->numbers->next($data->transactionTimeDevice),
            'shift_id' => $shift->id,
            'attendant_id' => $attendant->id,
            'location_id' => $location->id,
            'device_id' => $device->id,
            'sync_sequence' => $data->syncSequence,
            'vehicle_type' => $data->vehicleType,
            'vehicle_plate' => $data->vehiclePlate,
            'tariff_id' => $tariff?->id,
            'device_tariff_id' => $data->deviceTariffId,
            'charged_tariff_amount' => $data->chargedAmount,
            'server_expected_tariff_amount' => $tariff?->amount,
            'tariff_difference_amount' => $tariff === null ? null : $tariff->amount - $data->chargedAmount,
            'payment_method' => PaymentMethod::CASH,
            'status' => TransactionStatus::COMPLETED,
            'transaction_time_device' => $data->transactionTimeDevice,
            'transaction_time_server' => $now,
            'latitude' => $data->gps->latitude,
            'longitude' => $data->gps->longitude,
            'gps_accuracy_m' => $data->gps->accuracyM,
            'mock_location' => $data->gps->mockLocation,
            'geofence_result' => $check->result,
            'distance_m' => $check->distanceM,
            'offline_created' => $data->offlineCreated,
            'review_flags' => [],
            'payload_hash' => $hash,
        ]))->withFlags($flags);
        $transaction->save();

        $entry = $this->cashIn->handle($transaction);

        $this->audit->handle(AuditAction::CREATE_TRANSACTION, $user, 'parking_transaction', $transaction->transaction_uuid, [
            'transaction_number' => $transaction->transaction_number,
            'payment_method' => PaymentMethod::CASH->value,
            'vehicle_type' => $data->vehicleType->value,
            'charged_amount' => $data->chargedAmount,
            'expected_amount' => $tariff?->amount,
            'tariff_id' => $tariff?->id,
            'ledger_entry_id' => $entry->id,
            'offline_created' => $data->offlineCreated,
            'flags' => $transaction->review_flags,
        ], deviceUuid: $device->device_uuid);

        return new TransactionOutcome($transaction, created: true);
    }

    private function replay(ParkingAttendant $attendant, CashTransactionData $data, string $hash): ?TransactionOutcome
    {
        $existing = ParkingTransaction::query()->where('transaction_uuid', $data->transactionUuid)->first();
        if ($existing === null) {
            return null;
        }

        if ($existing->attendant_id !== $attendant->id || ! hash_equals($existing->payload_hash, $hash)) {
            throw new ApiException(ErrorCode::SYNC_CONFLICT, 'Transaksi dengan UUID ini sudah ada dengan data berbeda.', ['transaction_uuid' => $data->transactionUuid]);
        }

        return new TransactionOutcome($existing, created: false);
    }
}
