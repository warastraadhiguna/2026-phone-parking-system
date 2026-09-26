<?php

namespace App\Http\Admin\Assignment;

use App\Domain\Assignment\Actions\AssignAttendant;
use App\Domain\Assignment\Actions\CancelAssignment;
use App\Domain\Assignment\Actions\EndAssignment;
use App\Domain\Assignment\Models\Assignment;
use App\Domain\Identity\Models\User;
use App\Domain\ParkingAttendant\Models\ParkingAttendant;
use App\Domain\ParkingLocation\Models\ParkingLocation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Assignments are managed from the attendant page. */
final class AssignmentController
{
    public function store(Request $request, ParkingAttendant $attendant, AssignAttendant $assign): RedirectResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer', 'exists:parking_locations,id'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
        ], attributes: ['location_id' => 'lokasi', 'effective_from' => 'tanggal mulai', 'effective_until' => 'tanggal selesai']);

        /** @var User $actor */
        $actor = $request->user();
        $assign->handle(
            $attendant,
            ParkingLocation::query()->findOrFail((int) $data['location_id']),
            $data['effective_from'],
            $data['effective_until'] ?? null,
            $actor,
        );

        return back()->with('success', 'Penugasan dibuat.');
    }

    public function end(Request $request, Assignment $assignment, EndAssignment $end): RedirectResponse
    {
        $data = $request->validate([
            'effective_until' => ['required', 'date_format:Y-m-d'],
        ], attributes: ['effective_until' => 'tanggal selesai']);

        /** @var User $actor */
        $actor = $request->user();
        $end->handle($assignment, $data['effective_until'], $actor);

        return back()->with('success', 'Tanggal selesai penugasan diperbarui.');
    }

    public function cancel(Request $request, Assignment $assignment, CancelAssignment $cancel): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], attributes: ['reason' => 'alasan']);

        /** @var User $actor */
        $actor = $request->user();
        $cancel->handle($assignment, $data['reason'], $actor);

        return back()->with('success', 'Penugasan dibatalkan.');
    }
}
