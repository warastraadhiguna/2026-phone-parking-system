<?php

namespace App\Domain\ParkingAttendant\Actions;

use App\Domain\ParkingAttendant\Enums\AttendantStatus;
use App\Domain\ParkingAttendant\Models\ParkingAttendant;
use App\Support\Time\BusinessTime;

/**
 * Daily job: ACTIVE attendants whose registration validity ended before today become EXPIRED
 * (their login account is deactivated). Idempotent. Audited per attendant as a SYSTEM action.
 */
final class ExpireAttendants
{
    public function __construct(private readonly ChangeAttendantStatus $changeStatus) {}

    /** @return int number of attendants expired */
    public function handle(): int
    {
        $expired = 0;

        ParkingAttendant::query()
            ->where('status', AttendantStatus::ACTIVE->value)
            ->whereNotNull('expired_at')
            ->where('expired_at', '<', BusinessTime::today())
            ->orderBy('id')
            ->each(function (ParkingAttendant $attendant) use (&$expired) {
                $this->changeStatus->handle($attendant, AttendantStatus::EXPIRED, 'Masa berlaku registrasi berakhir', actor: null);
                $expired++;
            });

        return $expired;
    }
}
