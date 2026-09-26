<?php

namespace App\Http\Api\V1\ParkingTransaction;

use App\Domain\CashLedger\Services\CashBalances;
use App\Domain\Device\Models\Device;
use App\Domain\Identity\Models\User;
use App\Domain\ParkingAttendant\Models\ParkingAttendant;
use App\Domain\ParkingTransaction\Actions\RecordCashTransaction;
use App\Domain\ParkingTransaction\Actions\RequestVoid;
use App\Domain\ParkingTransaction\Enums\VoidChannel;
use App\Domain\ParkingTransaction\Models\ParkingTransaction;
use App\Http\Middleware\EnsureActiveDevice;
use App\Support\Errors\ApiException;
use App\Support\Errors\ErrorCode;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Parking transactions for the Android app. Contract: docs/api/transactions.md.
 */
final class TransactionController
{
    public function store(StoreTransactionRequest $request, RecordCashTransaction $record, CashBalances $balances): JsonResponse
    {
        [$user, $attendant, $device] = $this->actor($request);
        $outcome = $record->handle($user, $attendant, $device, $request->toData());
        $outcome->transaction->loadMissing('shift');

        return ApiResponse::success([
            'transaction' => TransactionResource::make($outcome->transaction),
            'replayed' => ! $outcome->created,
            'cash_balance' => $balances->of($attendant->id),
        ], status: $outcome->created ? 201 : 200);
    }

    public function index(Request $request): JsonResponse
    {
        [, $attendant] = $this->actor($request);
        $data = $request->validate(['shift_uuid' => ['nullable', 'uuid'], 'per_page' => ['nullable', 'integer', 'between:1,100']]);

        $page = ParkingTransaction::query()
            ->with('shift:id,shift_uuid')
            ->where('attendant_id', $attendant->id)
            ->when($data['shift_uuid'] ?? null, fn ($q, string $uuid) => $q->whereHas('shift', fn ($s) => $s->where('shift_uuid', strtolower($uuid))))
            ->orderByDesc('transaction_time_server')
            ->paginate((int) ($data['per_page'] ?? 50));

        return ApiResponse::success(
            array_map(fn (ParkingTransaction $t) => TransactionResource::make($t), $page->items()),
            meta: ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        );
    }

    public function show(Request $request, string $transactionUuid): JsonResponse
    {
        return ApiResponse::success(['transaction' => TransactionResource::make($this->own($request, $transactionUuid))]);
    }

    public function requestVoid(Request $request, string $transactionUuid, RequestVoid $requestVoid): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $transaction = $this->own($request, $transactionUuid);
        /** @var User $user */
        $user = $request->user();

        $void = $requestVoid->handle($transaction, $user, VoidChannel::MOBILE, $data['reason']);

        return ApiResponse::success(['void_request_id' => $void->id, 'status' => $void->status->value], status: 201);
    }

    public function balance(Request $request, CashBalances $balances): JsonResponse
    {
        [, $attendant] = $this->actor($request);

        return ApiResponse::success(['cash_balance' => $balances->of($attendant->id), 'as_of' => now()->toIso8601String()]);
    }

    private function own(Request $request, string $uuid): ParkingTransaction
    {
        [, $attendant] = $this->actor($request);

        return ParkingTransaction::query()->with('shift:id,shift_uuid')
            ->where('transaction_uuid', strtolower($uuid))
            ->where('attendant_id', $attendant->id)
            ->first() ?? throw new ApiException(ErrorCode::NOT_FOUND, 'Transaksi tidak ditemukan.');
    }

    /** @return array{0: User, 1: ParkingAttendant, 2: Device} */
    private function actor(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        /** @var Device $device */
        $device = $request->attributes->get(EnsureActiveDevice::DEVICE_ATTRIBUTE);
        /** @var ParkingAttendant $attendant */
        $attendant = $device->attendant;

        return [$user, $attendant, $device];
    }
}
