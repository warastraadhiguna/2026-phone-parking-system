<?php

namespace App\Http\Admin\Shift;

use App\Domain\Identity\Models\User;
use App\Domain\ParkingLocation\Models\ParkingLocation;
use App\Domain\Shift\Actions\ForceCloseShift;
use App\Domain\Shift\Enums\ShiftFlag;
use App\Domain\Shift\Enums\ShiftStatus;
use App\Domain\Shift\Models\Shift;
use App\Domain\SystemConfiguration\Enums\SettingKey;
use App\Domain\SystemConfiguration\Services\Settings;
use App\Http\Admin\Support\Options;
use App\Support\Time\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class ShiftController
{
    public function index(Request $request, Settings $settings): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ShiftStatus::class)],
            'location_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'flagged' => ['nullable', 'boolean'],
        ]);

        $shifts = Shift::query()
            ->with(['attendant:id,attendant_code,name', 'location:id,location_code,name'])
            ->when($filters['status'] ?? null, fn ($q, string $v) => $q->where('status', $v))
            ->when($filters['location_id'] ?? null, fn ($q, int|string $v) => $q->where('location_id', (int) $v))
            ->when($filters['date'] ?? null, function ($q, string $date) {
                $start = CarbonImmutable::parse($date, BusinessTime::timezone())->startOfDay()->utc();
                $q->whereBetween('started_at_server', [$start, $start->addDay()]);
            })
            ->when($filters['flagged'] ?? false, fn ($q) => $q->whereRaw('jsonb_array_length(review_flags) > 0'))
            ->orderByDesc('started_at_server')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Shift $s) => $this->row($s));

        return Inertia::render('Shifts/Index', [
            'shifts' => $shifts,
            'filters' => [
                'status' => $filters['status'] ?? '',
                'location_id' => (string) ($filters['location_id'] ?? ''),
                'date' => $filters['date'] ?? '',
                'flagged' => (bool) ($filters['flagged'] ?? false),
            ],
            'statuses' => Options::of(ShiftStatus::class),
            'locations' => ParkingLocation::query()->orderBy('location_code')->get(['id', 'location_code', 'name'])
                ->map(fn (ParkingLocation $l) => ['value' => (string) $l->id, 'label' => "{$l->location_code} — {$l->name}"]),
            'maxOpenHours' => $settings->int(SettingKey::MAX_OPEN_SHIFT_HOURS),
        ]);
    }

    public function show(Shift $shift): Response
    {
        $shift->load(['attendant:id,attendant_code,name', 'location:id,location_code,name,latitude,longitude,geofence_radius_m', 'device:id,device_uuid,device_model']);

        return Inertia::render('Shifts/Show', [
            'shift' => [
                ...$this->row($shift),
                'device' => $shift->device?->only(['device_uuid', 'device_model']),
                'assignment_id' => $shift->assignment_id,
                'start' => [
                    'latitude' => $shift->start_latitude,
                    'longitude' => $shift->start_longitude,
                    'accuracy_m' => $shift->start_gps_accuracy_m,
                    'mock' => $shift->start_mock_location,
                ],
                'end' => [
                    'geofence' => $shift->end_geofence_result !== null ? Options::one($shift->end_geofence_result) : null,
                    'distance_m' => $shift->end_distance_m,
                    'latitude' => $shift->end_latitude,
                    'longitude' => $shift->end_longitude,
                    'accuracy_m' => $shift->end_gps_accuracy_m,
                    'mock' => $shift->end_mock_location,
                ],
                'close_reason' => $shift->close_reason,
            ],
        ]);
    }

    public function forceClose(Request $request, Shift $shift, ForceCloseShift $forceClose): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], attributes: ['reason' => 'alasan']);

        /** @var User $actor */
        $actor = $request->user();
        $forceClose->handle($shift, $data['reason'], $actor);

        return back()->with('success', 'Shift ditutup paksa.');
    }

    /** @return array<string, mixed> */
    private function row(Shift $s): array
    {
        return [
            'id' => $s->id,
            'shift_uuid' => $s->shift_uuid,
            'status' => Options::one($s->status),
            'attendant' => $s->attendant?->only(['id', 'attendant_code', 'name']),
            'location' => $s->location?->only(['id', 'location_code', 'name']),
            'offline_created' => $s->offline_created,
            'started_at_device' => $s->started_at_device->toIso8601String(),
            'started_at_server' => $s->started_at_server->toIso8601String(),
            'ended_at_device' => $s->ended_at_device?->toIso8601String(),
            'ended_at_server' => $s->ended_at_server?->toIso8601String(),
            'start_geofence' => Options::one($s->start_geofence_result),
            'start_distance_m' => $s->start_distance_m,
            'flags' => array_map(fn (string $f) => Options::one(ShiftFlag::from($f)), $s->review_flags),
        ];
    }
}
