<?php

namespace App\Http\Api\V1\Identity;

use App\Domain\Assignment\Services\AssignmentLookup;
use App\Domain\Device\Services\DeviceAccess;
use App\Domain\Device\Services\DeviceGatekeeper;
use App\Domain\Identity\Actions\EndMobileSession;
use App\Domain\Identity\Actions\RefreshMobileSession;
use App\Domain\Identity\Actions\StartMobileSession;
use App\Domain\Identity\Data\MobileTokenPair;
use App\Domain\Identity\Models\User;
use App\Domain\ParkingAttendant\Models\ParkingAttendant;
use App\Http\Api\V1\Identity\Requests\MobileLoginRequest;
use App\Http\Api\V1\Identity\Requests\MobileRefreshRequest;
use App\Support\Http\ApiResponse;
use App\Support\RequestContext\RequestContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Attendant authentication for the Android app. Contract: docs/api/auth.md.
 */
final class AuthController
{
    public function login(MobileLoginRequest $request, StartMobileSession $start): JsonResponse
    {
        $login = $start->handle(
            (string) $request->string('username'),
            (string) $request->string('password'),
            $request->device(),
        );

        return ApiResponse::success(
            [...$this->tokens($login->tokens), 'user' => $this->user($login->user), 'device' => $login->device],
            headers: ['Cache-Control' => 'no-store'],
        );
    }

    public function refresh(MobileRefreshRequest $request, RefreshMobileSession $refresh): JsonResponse
    {
        $pair = $refresh->handle(
            (string) $request->string('refresh_token'),
            strtolower((string) $request->string('device_uuid')),
        );

        return ApiResponse::success($this->tokens($pair), headers: ['Cache-Control' => 'no-store']);
    }

    public function logout(Request $request, EndMobileSession $end): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var PersonalAccessToken $token */
        $token = $user->currentAccessToken();

        $end->handle($user, $token);

        return ApiResponse::success(['logged_out' => true]);
    }

    /** Profile, device approval status and today's assignment: what the app shows on its home screen. */
    public function me(Request $request, DeviceAccess $devices, AssignmentLookup $assignments): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $attendant = ParkingAttendant::query()->where('user_id', $user->id)->first();
        $device = $devices->find($user, RequestContext::deviceUuid());
        $assignment = $attendant !== null ? $assignments->currentFor($attendant) : null;

        return ApiResponse::success([
            'user' => $this->user($user),
            'attendant' => $attendant === null ? null : [
                'attendant_code' => $attendant->attendant_code,
                'name' => $attendant->name,
                'status' => $attendant->status->value,
                'expired_at' => $attendant->expired_at?->toDateString(),
            ],
            'device' => $device === null ? null : DeviceGatekeeper::summary($device),
            'assignment' => $assignment === null ? null : [
                'id' => $assignment->id,
                'location_code' => $assignment->location?->location_code,
                'location_name' => $assignment->location?->name,
                'effective_from' => $assignment->effective_from->toDateString(),
                'effective_until' => $assignment->effective_until?->toDateString(),
            ],
        ]);
    }

    /** @return array<string, string> */
    private function tokens(MobileTokenPair $pair): array
    {
        return [
            'token_type' => 'Bearer',
            'access_token' => $pair->accessToken,
            'access_token_expires_at' => $pair->accessTokenExpiresAt->toIso8601String(),
            'refresh_token' => $pair->refreshToken,
            'refresh_token_expires_at' => $pair->refreshTokenExpiresAt->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function user(User $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'name' => $user->name,
            'account_type' => $user->account_type->value,
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $user->permissionNames(),
        ];
    }
}
