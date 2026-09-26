<?php

namespace App\Http\Admin\ParkingAttendant;

use App\Domain\Assignment\Models\Assignment;
use App\Domain\CashLedger\Services\CashBalances;
use App\Domain\Device\Models\Device;
use App\Domain\Identity\Models\User;
use App\Domain\ParkingAttendant\Actions\ChangeAttendantStatus;
use App\Domain\ParkingAttendant\Actions\RegisterAttendant;
use App\Domain\ParkingAttendant\Actions\SetAttendantPhoto;
use App\Domain\ParkingAttendant\Actions\UpdateAttendant;
use App\Domain\ParkingAttendant\Enums\AttendantStatus;
use App\Domain\ParkingAttendant\Models\ParkingAttendant;
use App\Domain\ParkingLocation\Enums\LocationStatus;
use App\Domain\ParkingLocation\Models\ParkingLocation;
use App\Http\Admin\Support\Options;
use App\Support\Time\BusinessTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AttendantController
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(AttendantStatus::class)],
        ]);
        $today = BusinessTime::today();

        $attendants = ParkingAttendant::query()
            ->with([
                'assignments' => fn ($q) => $q->coveringDate($today)->with('location:id,location_code,name'),
                'devices' => fn ($q) => $q->whereIn('status', ['ACTIVE', 'PENDING_APPROVAL']),
            ])
            ->when($filters['q'] ?? null, fn ($q, string $term) => $q->where(fn ($w) => $w
                ->where('attendant_code', 'ilike', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('name', 'ilike', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('phone', 'ilike', '%'.addcslashes($term, '%_\\').'%')))
            ->when($filters['status'] ?? null, fn ($q, string $v) => $q->where('status', $v))
            ->orderBy('attendant_code')
            ->paginate(20)
            ->withQueryString()
            ->through(function (ParkingAttendant $a) {
                /** @var Assignment|null $current */
                $current = $a->assignments->first();

                return [
                    'id' => $a->id,
                    'attendant_code' => $a->attendant_code,
                    'name' => $a->name,
                    'phone' => $a->phone,
                    'identity_number_masked' => $a->maskedIdentityNumber(),
                    'status' => Options::one($a->status),
                    'expired_at' => $a->expired_at?->toDateString(),
                    'current_location' => $current?->location?->only(['location_code', 'name']),
                    'devices' => $a->devices->map(fn (Device $d) => Options::one($d->status))->values(),
                ];
            });

        return Inertia::render('Attendants/Index', [
            'attendants' => $attendants,
            'filters' => ['q' => $filters['q'] ?? '', 'status' => $filters['status'] ?? ''],
            'statuses' => Options::of(AttendantStatus::class),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Attendants/Create', ['today' => BusinessTime::today()]);
    }

    public function store(AttendantRequest $request, RegisterAttendant $register): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $attendant = $register->handle($request->toData(), (string) $request->string('password'), $actor);

        return redirect()->route('attendants.show', $attendant)
            ->with('success', "Juru parkir {$attendant->attendant_code} terdaftar. Username login: ".strtolower($attendant->attendant_code));
    }

    public function show(Request $request, ParkingAttendant $attendant, CashBalances $balances): Response
    {
        $today = BusinessTime::today();
        /** @var User $viewer */
        $viewer = $request->user();

        return Inertia::render('Attendants/Show', [
            'attendant' => [
                'id' => $attendant->id,
                'attendant_code' => $attendant->attendant_code,
                'username' => $attendant->user?->username,
                'user_id' => $attendant->user_id,
                'name' => $attendant->name,
                // Full NIK only for those who may edit it.
                'identity_number' => $viewer->can('attendants.manage') ? $attendant->identity_number : $attendant->maskedIdentityNumber(),
                'phone' => $attendant->phone,
                'status' => Options::one($attendant->status),
                'registered_at' => $attendant->registered_at->toDateString(),
                'expired_at' => $attendant->expired_at?->toDateString(),
                'has_photo' => $attendant->photo_path !== null,
                'cash_balance' => $balances->of($attendant->id),
            ],
            'assignments' => $attendant->assignments()->with('location:id,location_code,name')->orderByDesc('effective_from')->limit(50)->get()
                ->map(fn (Assignment $a) => [
                    'id' => $a->id,
                    'location_code' => $a->location?->location_code,
                    'location_name' => $a->location?->name,
                    'effective_from' => $a->effective_from->toDateString(),
                    'effective_until' => $a->effective_until?->toDateString(),
                    'phase' => Options::one($a->phaseOn($today)),
                    'cancel_reason' => $a->cancel_reason,
                ]),
            'devices' => $attendant->devices()->orderByDesc('registered_at')->get()
                ->map(fn (Device $d) => [
                    'id' => $d->id,
                    'device_uuid' => $d->device_uuid,
                    'device_model' => $d->device_model,
                    'app_version' => $d->app_version,
                    'status' => Options::one($d->status),
                    'registered_at' => $d->registered_at->toIso8601String(),
                    'last_seen_at' => $d->last_seen_at?->toIso8601String(),
                    'deactivation_reason' => $d->deactivation_reason,
                ]),
            'locations' => ParkingLocation::query()->where('status', LocationStatus::ACTIVE->value)->orderBy('location_code')
                ->get(['id', 'location_code', 'name'])
                ->map(fn (ParkingLocation $l) => ['value' => (string) $l->id, 'label' => "{$l->location_code} — {$l->name}"]),
            'statuses' => Options::of(AttendantStatus::class),
            'today' => $today,
        ]);
    }

    public function update(AttendantRequest $request, ParkingAttendant $attendant, UpdateAttendant $update): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $update->handle($attendant, $request->toData(), $actor);

        return back()->with('success', 'Data juru parkir disimpan.');
    }

    public function updateStatus(Request $request, ParkingAttendant $attendant, ChangeAttendantStatus $change): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(AttendantStatus::class)],
            'reason' => ['required', 'string', 'max:500'],
        ], attributes: ['reason' => 'alasan']);

        /** @var User $actor */
        $actor = $request->user();
        $change->handle($attendant, AttendantStatus::from($data['status']), $data['reason'], $actor);

        return back()->with('success', 'Status juru parkir diperbarui.');
    }

    public function storePhoto(Request $request, ParkingAttendant $attendant, SetAttendantPhoto $setPhoto): RedirectResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:2048', 'dimensions:min_width=100,min_height=100,max_width=4000,max_height=4000'],
        ], attributes: ['photo' => 'foto']);

        /** @var UploadedFile $file */
        $file = $request->file('photo');
        /** @var User $actor */
        $actor = $request->user();
        $setPhoto->handle($attendant, (string) $file->get(), $file->extension() ?? 'jpg', $actor);

        return back()->with('success', 'Foto diperbarui.');
    }

    public function photo(ParkingAttendant $attendant): StreamedResponse
    {
        abort_if($attendant->photo_path === null, 404);

        return Storage::disk(SetAttendantPhoto::DISK)->response($attendant->photo_path, headers: [
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
