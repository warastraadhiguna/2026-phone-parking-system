<?php

namespace App\Http\Api\V1\CashSettlement;

use App\Domain\CashLedger\Services\CashBalances;
use App\Domain\CashLedger\Services\CashSummary;
use App\Domain\CashSettlement\Actions\CancelSettlement;
use App\Domain\CashSettlement\Actions\SubmitSettlement;
use App\Domain\CashSettlement\Data\SettlementSubmission;
use App\Domain\CashSettlement\Enums\SettlementStatus;
use App\Domain\CashSettlement\Models\CashSettlement;
use App\Domain\Device\Models\Device;
use App\Domain\Identity\Models\User;
use App\Domain\ParkingAttendant\Models\ParkingAttendant;
use App\Domain\Shift\Enums\ShiftStatus;
use App\Domain\Shift\Models\Shift;
use App\Http\Middleware\EnsureActiveDevice;
use App\Support\Errors\ApiException;
use App\Support\Errors\ErrorCode;
use App\Support\Http\ApiResponse;
use App\Support\Time\BusinessTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Cash summary and deposits for the Android app (master doc §11, §13). Contract: docs/api/settlements.md.
 */
final class SettlementController
{
    public function summary(Request $request, CashSummary $summary, CashBalances $balances): JsonResponse
    {
        [, $attendant] = $this->actor($request);
        $today = now(BusinessTime::timezone())->toDateString();
        $shift = Shift::query()->where('attendant_id', $attendant->id)->where('status', ShiftStatus::OPEN->value)->first();
        $open = CashSettlement::query()->where('attendant_id', $attendant->id)->where('status', SettlementStatus::SUBMITTED->value)->first();

        return ApiResponse::success([
            'cash_balance' => $balances->of($attendant->id),
            'total' => $summary->forAttendant($attendant->id),
            'today' => ['date' => $today, ...$summary->forDay($attendant->id, $today)],
            'open_shift' => $shift === null ? null : ['shift_uuid' => $shift->shift_uuid, ...$summary->forShift($shift->id)],
            'pending_settlement' => $open === null ? null : SettlementResource::make($open),
            'as_of' => now()->toIso8601String(),
        ]);
    }

    public function store(Request $request, SubmitSettlement $submit, CashBalances $balances): JsonResponse
    {
        [$user, $attendant, $device] = $this->actor($request);
        $data = $request->validate([
            'settlement_uuid' => ['required', 'uuid'],
            'amount' => ['required', 'integer', 'between:1,100000000'],
            'shift_uuid' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:500'],
            'proof' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ]);

        /** @var UploadedFile|null $proof */
        $proof = $request->file('proof');
        $outcome = $submit->handle($user, $attendant, $device, new SettlementSubmission(
            strtolower((string) $data['settlement_uuid']),
            (int) $data['amount'],
            isset($data['shift_uuid']) ? strtolower((string) $data['shift_uuid']) : null,
            isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
            $proof?->get() ?: null,
            $proof?->extension(),
        ));

        return ApiResponse::success([
            'settlement' => SettlementResource::make($outcome['settlement']),
            'replayed' => ! $outcome['created'],
            'cash_balance' => $balances->of($attendant->id),
        ], status: $outcome['created'] ? 201 : 200);
    }

    public function index(Request $request): JsonResponse
    {
        [, $attendant] = $this->actor($request);
        $page = CashSettlement::query()->where('attendant_id', $attendant->id)->orderByDesc('submitted_at')->paginate(20);

        return ApiResponse::success(
            array_map(fn (CashSettlement $s) => SettlementResource::make($s), $page->items()),
            meta: ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        );
    }

    public function show(Request $request, string $settlementUuid): JsonResponse
    {
        return ApiResponse::success(['settlement' => SettlementResource::make($this->own($request, $settlementUuid))]);
    }

    public function cancel(Request $request, string $settlementUuid, CancelSettlement $cancel): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success(['settlement' => SettlementResource::make($cancel->handle($this->own($request, $settlementUuid), $user))]);
    }

    private function own(Request $request, string $uuid): CashSettlement
    {
        [, $attendant] = $this->actor($request);

        return CashSettlement::query()->where('settlement_uuid', strtolower($uuid))->where('attendant_id', $attendant->id)->first()
            ?? throw new ApiException(ErrorCode::NOT_FOUND, 'Setoran tidak ditemukan.');
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
