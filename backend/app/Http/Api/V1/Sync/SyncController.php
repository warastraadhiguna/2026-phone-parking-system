<?php

namespace App\Http\Api\V1\Sync;

use App\Domain\CashLedger\Services\CashBalances;
use App\Domain\Device\Models\Device;
use App\Domain\Identity\Models\User;
use App\Domain\ParkingAttendant\Models\ParkingAttendant;
use App\Domain\ParkingTransaction\Actions\RecordCashTransaction;
use App\Http\Api\V1\ParkingTransaction\CashTransactionPayload;
use App\Http\Api\V1\ParkingTransaction\TransactionResource;
use App\Http\Middleware\EnsureActiveDevice;
use App\Support\Errors\ApiException;
use App\Support\Errors\ErrorCode;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Batch sync of queued transactions (master doc §21, §41; ADR-0008). Contract: docs/api/sync.md.
 *
 * Items are processed in the order sent, each in its own DB transaction, so one bad item never
 * blocks the others. Every item gets a result:
 *   CREATED  — stored now;  EXISTING — already stored (retry), same data;
 *   REJECTED — not stored, with a stable error code; the device decides retry vs. give up.
 */
final class SyncController
{
    public const MAX_ITEMS = 50;

    /** Errors after which a retry of the same payload can succeed later (e.g. shift not synced yet). */
    private const RETRYABLE = [ErrorCode::SHIFT_NOT_ACTIVE, ErrorCode::INTERNAL_ERROR, ErrorCode::SERVICE_UNAVAILABLE];

    public function transactions(Request $request, RecordCashTransaction $record, CashBalances $balances): JsonResponse
    {
        $request->validate([
            'transactions' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'transactions.*' => ['required', 'array'],
        ]);

        /** @var User $user */
        $user = $request->user();
        /** @var Device $device */
        $device = $request->attributes->get(EnsureActiveDevice::DEVICE_ATTRIBUTE);
        /** @var ParkingAttendant $attendant */
        $attendant = $device->attendant;

        $results = [];
        $counts = ['CREATED' => 0, 'EXISTING' => 0, 'REJECTED' => 0];

        /** @var array<int, array<string, mixed>> $items */
        $items = (array) $request->input('transactions');
        foreach ($items as $index => $raw) {
            $result = $this->one($record, $user, $attendant, $device, CashTransactionPayload::normalise($raw), $index);
            $counts[$result['result']]++;
            $results[] = $result;
        }

        return ApiResponse::success([
            'results' => $results,
            'summary' => $counts,
            'cash_balance' => $balances->of($attendant->id),
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function one(RecordCashTransaction $record, User $user, ParkingAttendant $attendant, Device $device, array $input, int $index): array
    {
        $uuid = is_string($input['transaction_uuid'] ?? null) ? strtolower($input['transaction_uuid']) : null;

        $validator = Validator::make($input, CashTransactionPayload::rules());
        if ($validator->fails()) {
            return $this->rejected($uuid, $index, ErrorCode::VALIDATION_FAILED, $validator->errors()->first(), ['fields' => $validator->errors()->toArray()]);
        }

        try {
            $outcome = $record->handle($user, $attendant, $device, CashTransactionPayload::toData($validator->validated()));
            $outcome->transaction->loadMissing('shift');

            return [
                'index' => $index,
                'transaction_uuid' => $uuid,
                'result' => $outcome->created ? 'CREATED' : 'EXISTING',
                'transaction' => TransactionResource::make($outcome->transaction),
                'error' => null,
            ];
        } catch (ApiException $e) {
            return $this->rejected($uuid, $index, $e->errorCode, $e->getMessage(), $e->details);
        } catch (Throwable $e) {
            // Never let one item break the batch; details go to the log with the request id.
            Log::error('Sync item failed', ['transaction_uuid' => $uuid, 'exception' => $e::class, 'message' => $e->getMessage()]);

            return $this->rejected($uuid, $index, ErrorCode::INTERNAL_ERROR, ErrorCode::INTERNAL_ERROR->defaultMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function rejected(?string $uuid, int $index, ErrorCode $code, string $message, array $details = []): array
    {
        return [
            'index' => $index,
            'transaction_uuid' => $uuid,
            'result' => 'REJECTED',
            'transaction' => null,
            'error' => [
                'code' => $code->value,
                'message' => $message,
                'retryable' => in_array($code, self::RETRYABLE, true),
                ...($details === [] ? [] : ['details' => $details]),
            ],
        ];
    }
}
