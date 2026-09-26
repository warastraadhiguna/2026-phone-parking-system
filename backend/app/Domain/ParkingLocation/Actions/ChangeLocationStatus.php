<?php

namespace App\Domain\ParkingLocation\Actions;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Models\User;
use App\Domain\ParkingLocation\Enums\LocationStatus;
use App\Domain\ParkingLocation\Models\ParkingLocation;
use Illuminate\Support\Facades\DB;

/**
 * A non-active location accepts no new assignments (and, from Phase 3, no shifts).
 * Existing assignments are kept for history.
 */
final class ChangeLocationStatus
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(ParkingLocation $location, LocationStatus $status, ?string $reason, ?User $actor): ParkingLocation
    {
        return DB::transaction(function () use ($location, $status, $reason, $actor) {
            $location = ParkingLocation::query()->lockForUpdate()->findOrFail($location->id);
            $from = $location->status;
            if ($from === $status) {
                return $location;
            }

            $location->forceFill(['status' => $status])->save();

            $this->audit->handle(AuditAction::LOCATION_STATUS_CHANGED, $actor, 'parking_location', $location->id, array_filter([
                'from' => $from->value,
                'to' => $status->value,
                'reason' => $reason,
            ], fn ($v) => $v !== null));

            return $location;
        });
    }
}
