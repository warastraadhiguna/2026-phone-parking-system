<?php

namespace App\Domain\ParkingAttendant\Actions;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Actions\ChangeUserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\ParkingAttendant\Enums\AttendantStatus;
use App\Domain\ParkingAttendant\Models\ParkingAttendant;
use Illuminate\Support\Facades\DB;

/**
 * Changes the attendant status and mirrors it on the login account (which ends mobile
 * sessions when the attendant becomes non-operational).
 */
final class ChangeAttendantStatus
{
    public function __construct(
        private readonly ChangeUserStatus $changeUserStatus,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(ParkingAttendant $attendant, AttendantStatus $status, ?string $reason, ?User $actor): ParkingAttendant
    {
        return DB::transaction(function () use ($attendant, $status, $reason, $actor) {
            $attendant = ParkingAttendant::query()->lockForUpdate()->findOrFail($attendant->id);
            $from = $attendant->status;
            if ($from === $status) {
                return $attendant;
            }

            $attendant->forceFill(['status' => $status])->save();

            /** @var User $user */
            $user = $attendant->user;
            $this->changeUserStatus->handle($user, $status->userStatus(), $reason, $actor);

            $this->audit->handle(AuditAction::ATTENDANT_STATUS_CHANGED, $actor, 'parking_attendant', $attendant->id, array_filter([
                'from' => $from->value,
                'to' => $status->value,
                'reason' => $reason,
            ], fn ($v) => $v !== null));

            return $attendant;
        });
    }
}
