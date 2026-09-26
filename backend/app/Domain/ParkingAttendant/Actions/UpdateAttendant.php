<?php

namespace App\Domain\ParkingAttendant\Actions;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Actions\UpdateUser;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\ParkingAttendant\Data\AttendantData;
use App\Domain\ParkingAttendant\Models\ParkingAttendant;
use App\Support\Database\ChangeSet;
use Illuminate\Support\Facades\DB;

/**
 * Updates attendant data; the login account's display name follows. The code never changes.
 * A changed NIK is recorded as changed, never with its value.
 */
final class UpdateAttendant
{
    public function __construct(
        private readonly UpdateUser $updateUser,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(ParkingAttendant $attendant, AttendantData $data, ?User $actor): ParkingAttendant
    {
        return DB::transaction(function () use ($attendant, $data, $actor) {
            $attendant = ParkingAttendant::query()->lockForUpdate()->findOrFail($attendant->id);
            $attendant->fill($data->toAttributes());

            $changes = ChangeSet::of($attendant);
            if ($changes === []) {
                return $attendant;
            }
            if (isset($changes['identity_number'])) {
                $changes['identity_number'] = ['from' => '[changed]', 'to' => '[changed]'];
            }

            $attendant->save();

            if (isset($changes['name'])) {
                /** @var User $user */
                $user = $attendant->user;
                $this->updateUser->handle($user, $attendant->name, null, [Role::PARKING_ATTENDANT], $actor);
            }

            $this->audit->handle(AuditAction::ATTENDANT_CHANGED, $actor, 'parking_attendant', $attendant->id, ['changes' => $changes]);

            return $attendant;
        });
    }
}
