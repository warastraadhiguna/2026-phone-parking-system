<?php

namespace App\Http\Api\V1\Shift;

use App\Domain\Shift\Models\Shift;

/** Shift as returned to the Android app. */
final class ShiftResource
{
    /** @return array<string, mixed> */
    public static function make(Shift $shift): array
    {
        return [
            'shift_uuid' => $shift->shift_uuid,
            'status' => $shift->status->value,
            'offline_created' => $shift->offline_created,
            'location' => [
                'id' => $shift->location_id,
                'location_code' => $shift->location?->location_code,
                'name' => $shift->location?->name,
            ],
            'assignment_id' => $shift->assignment_id,
            'started_at_device' => $shift->started_at_device->toIso8601String(),
            'started_at_server' => $shift->started_at_server->toIso8601String(),
            'ended_at_device' => $shift->ended_at_device?->toIso8601String(),
            'ended_at_server' => $shift->ended_at_server?->toIso8601String(),
            'start_geofence_result' => $shift->start_geofence_result->value,
            'start_distance_m' => $shift->start_distance_m,
            'end_geofence_result' => $shift->end_geofence_result?->value,
            'review_flags' => $shift->review_flags,
            'close_reason' => $shift->close_reason,
        ];
    }
}
