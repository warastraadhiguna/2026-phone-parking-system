<?php

namespace App\Http\Admin\ParkingLocation;

use App\Domain\Assignment\Models\Assignment;
use App\Domain\Identity\Models\User;
use App\Domain\ParkingLocation\Actions\ChangeLocationStatus;
use App\Domain\ParkingLocation\Actions\CreateLocation;
use App\Domain\ParkingLocation\Actions\UpdateLocation;
use App\Domain\ParkingLocation\Enums\LocationStatus;
use App\Domain\ParkingLocation\Enums\LocationType;
use App\Domain\ParkingLocation\Models\ParkingLocation;
use App\Domain\Tariff\Models\Tariff;
use App\Domain\Tariff\Services\TariffResolver;
use App\Http\Admin\Support\Options;
use App\Support\Time\BusinessTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class LocationController
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'location_type' => ['nullable', Rule::enum(LocationType::class)],
            'status' => ['nullable', Rule::enum(LocationStatus::class)],
        ]);
        $today = BusinessTime::today();

        $locations = ParkingLocation::query()
            ->withCount(['assignments as current_attendants' => fn ($q) => $q->coveringDate($today)])
            ->when($filters['q'] ?? null, fn ($q, string $term) => $q->where(fn ($w) => $w
                ->where('location_code', 'ilike', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('name', 'ilike', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('address', 'ilike', '%'.addcslashes($term, '%_\\').'%')))
            ->when($filters['location_type'] ?? null, fn ($q, string $v) => $q->where('location_type', $v))
            ->when($filters['status'] ?? null, fn ($q, string $v) => $q->where('status', $v))
            ->orderBy('location_code')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (ParkingLocation $l) => [
                ...$this->row($l),
                'current_attendants' => (int) $l->getAttribute('current_attendants'),
            ]);

        return Inertia::render('Locations/Index', [
            'locations' => $locations,
            'filters' => ['q' => $filters['q'] ?? '', 'location_type' => $filters['location_type'] ?? '', 'status' => $filters['status'] ?? ''],
            'options' => ['location_types' => Options::of(LocationType::class), 'statuses' => Options::of(LocationStatus::class)],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Locations/Create', ['locationTypes' => Options::of(LocationType::class)]);
    }

    public function store(LocationRequest $request, CreateLocation $create): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $location = $create->handle((string) $request->string('location_code'), $request->toData(), $actor);

        return redirect()->route('locations.show', $location)->with('success', "Lokasi {$location->location_code} berhasil dibuat.");
    }

    public function show(ParkingLocation $location, TariffResolver $tariffs): Response
    {
        $today = BusinessTime::today();

        $assignments = Assignment::query()
            ->where('location_id', $location->id)
            ->coveringDate($today)
            ->with('attendant:id,attendant_code,name,status')
            ->orderBy('effective_from')
            ->get()
            ->map(fn (Assignment $a) => [
                'id' => $a->id,
                'attendant_id' => $a->attendant_id,
                'attendant_code' => $a->attendant?->attendant_code,
                'attendant_name' => $a->attendant?->name,
                'effective_from' => $a->effective_from->toDateString(),
                'effective_until' => $a->effective_until?->toDateString(),
            ]);

        return Inertia::render('Locations/Show', [
            'location' => $this->row($location),
            'assignments' => $assignments,
            'tariffs' => array_values(array_map(fn (Tariff $t) => [
                'id' => $t->id,
                'vehicle_type' => Options::one($t->vehicle_type),
                'amount' => $t->amount,
                'specific' => $t->location_id !== null,
                'regulation_reference' => $t->regulation_reference,
            ], $tariffs->allFor($location))),
            'locationTypes' => Options::of(LocationType::class),
            'statuses' => Options::of(LocationStatus::class),
        ]);
    }

    public function update(LocationRequest $request, ParkingLocation $location, UpdateLocation $update): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $update->handle($location, $request->toData(), $actor);

        return back()->with('success', 'Perubahan lokasi disimpan.');
    }

    public function updateStatus(Request $request, ParkingLocation $location, ChangeLocationStatus $change): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(LocationStatus::class)],
            'reason' => ['nullable', 'string', 'max:500'],
        ], attributes: ['reason' => 'alasan']);

        /** @var User $actor */
        $actor = $request->user();
        $change->handle($location, LocationStatus::from($data['status']), $data['reason'] ?? null, $actor);

        return back()->with('success', 'Status lokasi diperbarui.');
    }

    /** @return array<string, mixed> */
    private function row(ParkingLocation $l): array
    {
        return [
            'id' => $l->id,
            'location_code' => $l->location_code,
            'name' => $l->name,
            'address' => $l->address,
            'latitude' => $l->latitude,
            'longitude' => $l->longitude,
            'geofence_radius_m' => $l->geofence_radius_m,
            'location_type' => Options::one($l->location_type),
            'status' => Options::one($l->status),
            'motorcycle_capacity' => $l->motorcycle_capacity,
            'car_capacity' => $l->car_capacity,
        ];
    }
}
