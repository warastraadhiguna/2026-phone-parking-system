<?php

namespace App\Domain\ParkingAttendant\Actions;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Actions\CreateUser;
use App\Domain\Identity\Enums\AccountType;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\ParkingAttendant\Data\AttendantData;
use App\Domain\ParkingAttendant\Enums\AttendantStatus;
use App\Domain\ParkingAttendant\Models\ParkingAttendant;
use Illuminate\Support\Facades\DB;

/**
 * Registers a juru parkir and creates their mobile login account in one transaction.
 * The attendant code (JP-000001, …) comes from a database sequence and is also the username.
 */
final class RegisterAttendant
{
    public function __construct(
        private readonly CreateUser $createUser,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(AttendantData $data, #[\SensitiveParameter] string $initialPassword, ?User $actor): ParkingAttendant
    {
        return DB::transaction(function () use ($data, $initialPassword, $actor) {
            $sequence = (int) DB::scalar("SELECT nextval('parking_attendant_code_seq')");
            $code = sprintf('JP-%06d', $sequence);

            $user = $this->createUser->handle(
                strtolower($code),
                $data->name,
                null,
                $initialPassword,
                AccountType::ATTENDANT,
                [Role::PARKING_ATTENDANT],
                $actor,
            );

            $attendant = ParkingAttendant::create([
                'attendant_code' => $code,
                'user_id' => $user->id,
                ...$data->toAttributes(),
                'status' => AttendantStatus::ACTIVE,
            ]);

            $this->audit->handle(AuditAction::ATTENDANT_REGISTERED, $actor, 'parking_attendant', $attendant->id, [
                'attendant_code' => $code,
                'user_id' => $user->id,
                'registered_at' => $data->registeredAt,
                'expired_at' => $data->expiredAt,
            ]);

            return $attendant;
        });
    }
}
