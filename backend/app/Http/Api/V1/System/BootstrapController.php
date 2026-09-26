<?php

namespace App\Http\Api\V1\System;

use App\Domain\Assignment\Services\AssignmentLookup;
use App\Domain\Device\Models\Device;
use App\Domain\Device\Services\DeviceGatekeeper;
use App\Domain\ParkingAttendant\Models\ParkingAttendant;
use App\Domain\Shift\Services\ShiftLookup;
use App\Domain\SystemConfiguration\Enums\SettingKey;
use App\Domain\SystemConfiguration\Services\Settings;
use App\Domain\Tariff\Models\Tariff;
use App\Domain\Tariff\Services\TariffResolver;
use App\Http\Api\V1\Shift\ShiftResource;
use App\Http\Middleware\EnsureActiveDevice;
use App\Support\Http\ApiResponse;
use App\Support\Time\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Everything the app caches to work offline (ADR-0008): today's assignment and location,
 * tariffs in effect or starting within the cache validity window, policy settings and the open
 * shift. The app must not start an offline shift with data older than `valid_until`.
 */
final class BootstrapController
{
    public function __invoke(Request $request, AssignmentLookup $assignments, TariffResolver $tariffs, Settings $settings, ShiftLookup $shifts): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get(EnsureActiveDevice::DEVICE_ATTRIBUTE);
        /** @var ParkingAttendant $attendant */
        $attendant = $device->attendant;

        $now = CarbonImmutable::now();
        $validUntil = $now->addHours($settings->int(SettingKey::OFFLINE_CONFIG_MAX_AGE_HOURS));
        $assignment = $assignments->currentFor($attendant);
        $location = $assignment?->location;
        $openShift = $shifts->openFor($attendant);

        return ApiResponse::success([
            'generated_at' => $now->toIso8601String(),
            'valid_until' => $validUntil->toIso8601String(),
            'business_date' => BusinessTime::today(),
            'business_timezone' => BusinessTime::timezone(),
            'attendant' => [
                'attendant_code' => $attendant->attendant_code,
                'name' => $attendant->name,
                'status' => $attendant->status->value,
            ],
            'device' => DeviceGatekeeper::summary($device),
            'assignment' => $assignment === null ? null : [
                'id' => $assignment->id,
                'location_id' => $assignment->location_id,
                'effective_from' => $assignment->effective_from->toDateString(),
                'effective_until' => $assignment->effective_until?->toDateString(),
            ],
            'location' => $location === null ? null : [
                'id' => $location->id,
                'location_code' => $location->location_code,
                'name' => $location->name,
                'address' => $location->address,
                'latitude' => $location->latitude,
                'longitude' => $location->longitude,
                'geofence_radius_m' => $location->geofence_radius_m,
                'location_type' => $location->location_type->value,
                'status' => $location->status->value,
            ],
            'tariffs' => $location === null ? [] : array_map(fn (Tariff $t) => [
                'tariff_id' => $t->id,
                'vehicle_type' => $t->vehicle_type->value,
                'amount' => $t->amount,
                'location_specific' => $t->location_id !== null,
                'effective_from' => $t->effective_from->toIso8601String(),
                'effective_until' => $t->effective_until?->toIso8601String(),
            ], $tariffs->scheduleFor($location, $now, $validUntil)),
            'settings' => $settings->all(),
            'open_shift' => $openShift === null ? null : ShiftResource::make($openShift),
        ], headers: ['Cache-Control' => 'no-store']);
    }
}
