<?php

namespace App\Http\Api\V1\Shift;

use App\Domain\Device\Models\Device;
use App\Domain\Identity\Models\User;
use App\Domain\ParkingAttendant\Models\ParkingAttendant;
use App\Domain\Shift\Actions\EndShift;
use App\Domain\Shift\Actions\StartShift;
use App\Domain\Shift\Data\ShiftOutcome;
use App\Domain\Shift\Models\Shift;
use App\Domain\Shift\Services\ShiftLookup;
use App\Http\Middleware\EnsureActiveDevice;
use App\Support\Errors\ApiException;
use App\Support\Errors\ErrorCode;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shift endpoints for the Android app. Contract: docs/api/shifts.md.
 * Routes use auth:sanctum + mobile.attendant + mobile.device.
 */
final class ShiftController
{
    public function start(StartShiftRequest $request, StartShift $start): JsonResponse
    {
        [$user, $attendant, $device] = $this->actor($request);

        return $this->respond($start->handle($user, $attendant, $device, $request->toData()), createdStatus: 201);
    }

    public function end(EndShiftRequest $request, EndShift $end): JsonResponse
    {
        [$user, $attendant, $device] = $this->actor($request);

        return $this->respond($end->handle($user, $attendant, $device, $request->toData()), createdStatus: 200);
    }

    public function active(Request $request, ShiftLookup $shifts): JsonResponse
    {
        [, $attendant] = $this->actor($request);
        $shift = $shifts->openFor($attendant);

        return ApiResponse::success(['shift' => $shift === null ? null : ShiftResource::make($shift)]);
    }

    public function show(Request $request, string $shiftUuid): JsonResponse
    {
        [, $attendant] = $this->actor($request);

        $shift = Shift::query()->with('location')
            ->where('shift_uuid', strtolower($shiftUuid))
            ->where('attendant_id', $attendant->id)
            ->first() ?? throw new ApiException(ErrorCode::NOT_FOUND, 'Shift tidak ditemukan.');

        return ApiResponse::success(['shift' => ShiftResource::make($shift)]);
    }

    private function respond(ShiftOutcome $outcome, int $createdStatus): JsonResponse
    {
        $outcome->shift->loadMissing('location');

        return ApiResponse::success(
            ['shift' => ShiftResource::make($outcome->shift), 'replayed' => ! $outcome->created],
            status: $outcome->created ? $createdStatus : 200,
        );
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
