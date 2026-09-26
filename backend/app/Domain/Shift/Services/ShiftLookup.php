<?php

namespace App\Domain\Shift\Services;

use App\Domain\ParkingAttendant\Models\ParkingAttendant;
use App\Domain\Shift\Enums\ShiftStatus;
use App\Domain\Shift\Models\Shift;

/** Read-side queries for other modules (ParkingTransaction in Phase 4) and the API. */
final class ShiftLookup
{
    public function openFor(ParkingAttendant $attendant): ?Shift
    {
        return Shift::query()
            ->where('attendant_id', $attendant->id)
            ->where('status', ShiftStatus::OPEN->value)
            ->with('location')
            ->first();
    }
}
