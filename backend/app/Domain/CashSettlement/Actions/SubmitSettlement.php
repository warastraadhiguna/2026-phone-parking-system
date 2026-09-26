<?php

namespace App\Domain\CashSettlement\Actions;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\CashLedger\Services\CashBalances;
use App\Domain\CashSettlement\Data\SettlementSubmission;
use App\Domain\CashSettlement\Enums\SettlementStatus;
use App\Domain\CashSettlement\Internal\SettlementNumberGenerator;
use App\Domain\CashSettlement\Models\CashSettlement;
use App\Domain\Device\Models\Device;
use App\Domain\Identity\Models\User;
use App\Domain\ParkingAttendant\Models\ParkingAttendant;
use App\Domain\Shift\Models\Shift;
use App\Support\Errors\ApiException;
use App\Support\Errors\ErrorCode;
use App\Support\Idempotency\PayloadHash;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The attendant declares a cash deposit (master doc §13, Scenario D). Nothing moves in the
 * ledger yet: finance verifies the counted amount first (VerifySettlement).
 *
 * - Idempotent on settlement_uuid (payload fingerprint includes the proof photo hash).
 * - At most one open submission per attendant (also a partial unique index).
 * - The amount cannot exceed the cash the server knows the attendant holds; the app syncs
 *   first so that offline cash is included.
 *
 * @phpstan-type Outcome array{settlement: CashSettlement, created: bool}
 */
final class SubmitSettlement
{
    public const PROOF_DISK = 'local';

    public function __construct(
        private readonly CashBalances $balances,
        private readonly SettlementNumberGenerator $numbers,
        private readonly RecordAuditEvent $audit,
    ) {}

    /** @return Outcome */
    public function handle(User $user, ParkingAttendant $attendant, ?Device $device, SettlementSubmission $data): array
    {
        $hash = PayloadHash::of($data->fingerprint());

        $existing = $this->replay($attendant, $data, $hash);
        if ($existing !== null) {
            return ['settlement' => $existing, 'created' => false];
        }

        $proofPath = null;
        if ($data->proofContents !== null) {
            $proofPath = sprintf('settlements/%d/%s.%s', $attendant->id, $data->settlementUuid, strtolower((string) $data->proofExtension));
            Storage::disk(self::PROOF_DISK)->put($proofPath, $data->proofContents);
        }

        try {
            return DB::transaction(fn () => $this->record($user, $attendant, $device, $data, $hash, $proofPath));
        } catch (QueryException $e) {
            if ($e->getCode() !== '23505') {
                throw $e;
            }
            $existing = $this->replay($attendant, $data, $hash);
            if ($existing !== null) {
                return ['settlement' => $existing, 'created' => false];
            }
            throw new ApiException(ErrorCode::SETTLEMENT_INVALID, 'Masih ada setoran yang menunggu verifikasi.');
        }
    }

    /** @return Outcome */
    private function record(User $user, ParkingAttendant $attendant, ?Device $device, SettlementSubmission $data, string $hash, ?string $proofPath): array
    {
        $open = CashSettlement::query()->where('attendant_id', $attendant->id)->where('status', SettlementStatus::SUBMITTED->value)->first();
        if ($open !== null) {
            throw new ApiException(ErrorCode::SETTLEMENT_INVALID, 'Masih ada setoran yang menunggu verifikasi. Batalkan atau tunggu keputusan keuangan.', [
                'open_settlement_uuid' => $open->settlement_uuid,
            ]);
        }

        $shift = null;
        if ($data->shiftUuid !== null) {
            $shift = Shift::query()->where('shift_uuid', $data->shiftUuid)->where('attendant_id', $attendant->id)->first()
                ?? throw new ApiException(ErrorCode::SETTLEMENT_INVALID, 'Shift tidak ditemukan. Sinkronkan data terlebih dahulu.', ['shift_uuid' => $data->shiftUuid]);
        }

        $balance = $this->balances->of($attendant->id);
        if ($data->amount > $balance) {
            throw new ApiException(ErrorCode::SETTLEMENT_INVALID, 'Jumlah setoran melebihi kas yang tercatat di server. Sinkronkan transaksi terlebih dahulu.', [
                'cash_balance' => $balance,
            ]);
        }

        $now = CarbonImmutable::now();
        $settlement = CashSettlement::create([
            'settlement_uuid' => $data->settlementUuid,
            'settlement_number' => $this->numbers->next($now),
            'attendant_id' => $attendant->id,
            'shift_id' => $shift?->id,
            'device_id' => $device?->id,
            'amount' => $data->amount,
            'balance_at_submission' => $balance,
            'notes' => $data->notes,
            'proof_path' => $proofPath,
            'proof_sha256' => $data->proofContents === null ? null : hash('sha256', $data->proofContents),
            'status' => SettlementStatus::SUBMITTED,
            'submitted_by' => $user->id,
            'submitted_at' => $now,
            'payload_hash' => $hash,
        ]);

        $this->audit->handle(AuditAction::SETTLEMENT_SUBMITTED, $user, 'cash_settlement', $settlement->settlement_uuid, [
            'settlement_number' => $settlement->settlement_number,
            'amount' => $data->amount,
            'cash_balance' => $balance,
            'shift_id' => $shift?->id,
            'has_proof' => $proofPath !== null,
        ], deviceUuid: $device?->device_uuid);

        return ['settlement' => $settlement, 'created' => true];
    }

    private function replay(ParkingAttendant $attendant, SettlementSubmission $data, string $hash): ?CashSettlement
    {
        $existing = CashSettlement::query()->where('settlement_uuid', $data->settlementUuid)->first();
        if ($existing === null) {
            return null;
        }
        if ($existing->attendant_id !== $attendant->id || ! hash_equals($existing->payload_hash, $hash)) {
            throw new ApiException(ErrorCode::SYNC_CONFLICT, 'Setoran dengan UUID ini sudah ada dengan data berbeda.', ['settlement_uuid' => $data->settlementUuid]);
        }

        return $existing;
    }
}
