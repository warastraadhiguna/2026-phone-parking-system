<?php

namespace App\Domain\Assignment\Services;

use App\Domain\Assignment\Models\Assignment;
use App\Domain\ParkingAttendant\Models\ParkingAttendant;
use App\Support\Time\BusinessTime;

/** Read-side queries for other modules (Shift in Phase 3, mobile bootstrap). */
final class AssignmentLookup
{
    /** The assignment covering the given WIB day (default: today), if any. */
    public function currentFor(ParkingAttendant $attendant, ?string $date = null): ?Assignment
    {
        return Assignment::query()
            ->where('attendant_id', $attendant->id)
            ->coveringDate($date ?? BusinessTime::today())
            ->with('location')
            ->first();
    }
}
