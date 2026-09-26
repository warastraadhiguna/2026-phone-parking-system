<?php

namespace App\Http\Admin\Tariff;

use App\Domain\Identity\Models\User;
use App\Domain\ParkingLocation\Enums\LocationType;
use App\Domain\ParkingLocation\Models\ParkingLocation;
use App\Domain\Tariff\Actions\ApproveTariff;
use App\Domain\Tariff\Actions\CreateTariffDraft;
use App\Domain\Tariff\Actions\RejectTariff;
use App\Domain\Tariff\Actions\UpdateTariffDraft;
use App\Domain\Tariff\Enums\TariffStatus;
use App\Domain\Tariff\Enums\VehicleType;
use App\Domain\Tariff\Models\Tariff;
use App\Http\Admin\Support\Options;
use App\Support\Time\BusinessTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class TariffController
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(TariffStatus::class)],
            'vehicle_type' => ['nullable', Rule::enum(VehicleType::class)],
            'location_type' => ['nullable', Rule::enum(LocationType::class)],
        ]);

        $tariffs = Tariff::query()
            ->with(['location:id,location_code,name', 'creator:id,username', 'approver:id,username'])
            ->when($filters['status'] ?? null, fn ($q, string $v) => $q->where('status', $v))
            ->when($filters['vehicle_type'] ?? null, fn ($q, string $v) => $q->where('vehicle_type', $v))
            ->when($filters['location_type'] ?? null, fn ($q, string $v) => $q->where('location_type', $v))
            ->orderByRaw("CASE status WHEN 'DRAFT' THEN 0 WHEN 'APPROVED' THEN 1 ELSE 2 END")
            ->orderBy('location_type')->orderBy('vehicle_type')->orderByDesc('effective_from')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Tariff $t) => $this->row($t));

        return Inertia::render('Tariffs/Index', [
            'tariffs' => $tariffs,
            'filters' => [
                'status' => $filters['status'] ?? '',
                'vehicle_type' => $filters['vehicle_type'] ?? '',
                'location_type' => $filters['location_type'] ?? '',
            ],
            'options' => $this->options(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Tariffs/Form', ['tariff' => null, 'options' => $this->options()]);
    }

    public function store(TariffRequest $request, CreateTariffDraft $create): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $tariff = $create->handle($request->toData(), $actor);

        return redirect()->route('tariffs.show', $tariff)->with('success', 'Draf tarif dibuat. Tarif berlaku setelah disetujui pengguna lain.');
    }

    public function show(Request $request, Tariff $tariff): Response
    {
        $tariff->load(['location:id,location_code,name', 'creator:id,username,name', 'approver:id,username,name']);
        /** @var User $viewer */
        $viewer = $request->user();

        return Inertia::render('Tariffs/Show', [
            'tariff' => $this->row($tariff),
            'can' => [
                'edit' => $tariff->status === TariffStatus::DRAFT && $viewer->can('tariffs.manage'),
                'approve' => $tariff->status === TariffStatus::DRAFT && $viewer->can('tariffs.approve') && $tariff->created_by !== $viewer->id,
                'reject' => $tariff->status === TariffStatus::DRAFT && $viewer->can('tariffs.approve'),
            ],
        ]);
    }

    public function edit(Tariff $tariff): Response|RedirectResponse
    {
        if ($tariff->status !== TariffStatus::DRAFT) {
            return redirect()->route('tariffs.show', $tariff);
        }

        return Inertia::render('Tariffs/Form', ['tariff' => $this->row($tariff), 'options' => $this->options()]);
    }

    public function update(TariffRequest $request, Tariff $tariff, UpdateTariffDraft $update): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $update->handle($tariff, $request->toData(), $actor);

        return redirect()->route('tariffs.show', $tariff)->with('success', 'Draf tarif diperbarui.');
    }

    public function approve(Request $request, Tariff $tariff, ApproveTariff $approve): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $approve->handle($tariff, $actor);

        return back()->with('success', 'Tarif disetujui.');
    }

    public function reject(Request $request, Tariff $tariff, RejectTariff $reject): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], attributes: ['reason' => 'alasan']);

        /** @var User $actor */
        $actor = $request->user();
        $reject->handle($tariff, $data['reason'], $actor);

        return back()->with('success', 'Tarif ditolak.');
    }

    /** @return array<string, mixed> */
    private function row(Tariff $t): array
    {
        return [
            'id' => $t->id,
            'vehicle_type' => Options::one($t->vehicle_type),
            'location_type' => Options::one($t->location_type),
            'location' => $t->location?->only(['id', 'location_code', 'name']),
            'amount' => $t->amount,
            'effective_from' => $t->effective_from->toIso8601String(),
            'effective_from_local' => BusinessTime::toLocalInput($t->effective_from),
            'effective_until' => $t->effective_until?->toIso8601String(),
            'regulation_reference' => $t->regulation_reference,
            'status' => Options::one($t->status),
            'created_by' => $t->creator?->username,
            'approved_by' => $t->approver?->username,
            'approved_at' => $t->approved_at?->toIso8601String(),
            'rejection_reason' => $t->rejection_reason,
        ];
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'vehicle_types' => Options::of(VehicleType::class),
            'location_types' => Options::of(LocationType::class),
            'statuses' => Options::of(TariffStatus::class),
            'locations' => ParkingLocation::query()->orderBy('location_code')->get(['id', 'location_code', 'name', 'location_type'])
                ->map(fn (ParkingLocation $l) => [
                    'value' => (string) $l->id,
                    'label' => "{$l->location_code} — {$l->name}",
                    'location_type' => $l->location_type->value,
                ]),
        ];
    }
}
