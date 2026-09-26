<?php

namespace App\Http\Admin\Device;

use App\Domain\Device\Actions\ApproveDevice;
use App\Domain\Device\Actions\DeactivateDevice;
use App\Domain\Device\Enums\DeviceStatus;
use App\Domain\Device\Models\Device;
use App\Domain\Identity\Models\User;
use App\Http\Admin\Support\Options;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class DeviceController
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(DeviceStatus::class)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        // Default view: the approval queue.
        $status = $filters['status'] ?? ($request->has('status') ? null : DeviceStatus::PENDING_APPROVAL->value);

        $devices = Device::query()
            ->with('attendant:id,attendant_code,name')
            ->when($status, fn ($q, string $v) => $q->where('status', $v))
            ->when($filters['q'] ?? null, fn ($q, string $term) => $q->where(fn ($w) => $w
                ->where('device_uuid', 'ilike', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('device_model', 'ilike', '%'.addcslashes($term, '%_\\').'%')
                ->orWhereHas('attendant', fn ($a) => $a
                    ->where('attendant_code', 'ilike', '%'.addcslashes($term, '%_\\').'%')
                    ->orWhere('name', 'ilike', '%'.addcslashes($term, '%_\\').'%'))))
            ->orderByDesc('registered_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Device $d) => [
                'id' => $d->id,
                'device_uuid' => $d->device_uuid,
                'device_model' => $d->device_model,
                'android_version' => $d->android_version,
                'app_version' => $d->app_version,
                'status' => Options::one($d->status),
                'attendant' => $d->attendant?->only(['id', 'attendant_code', 'name']),
                'registered_at' => $d->registered_at->toIso8601String(),
                'last_seen_at' => $d->last_seen_at?->toIso8601String(),
                'deactivation_reason' => $d->deactivation_reason,
            ]);

        return Inertia::render('Devices/Index', [
            'devices' => $devices,
            'filters' => ['status' => $status ?? '', 'q' => $filters['q'] ?? ''],
            'statuses' => Options::of(DeviceStatus::class),
        ]);
    }

    public function approve(Request $request, Device $device, ApproveDevice $approve): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $approve->handle($device, $actor);

        return back()->with('success', 'Perangkat disetujui.');
    }

    public function deactivate(Request $request, Device $device, DeactivateDevice $deactivate): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([DeviceStatus::REVOKED->value, DeviceStatus::LOST->value])],
            'reason' => ['required', 'string', 'max:500'],
        ], attributes: ['reason' => 'alasan']);

        /** @var User $actor */
        $actor = $request->user();
        $deactivate->handle($device, DeviceStatus::from($data['status']), $data['reason'], $actor);

        return back()->with('success', 'Perangkat dinonaktifkan. Sesi aplikasi pada perangkat tersebut diakhiri.');
    }
}
