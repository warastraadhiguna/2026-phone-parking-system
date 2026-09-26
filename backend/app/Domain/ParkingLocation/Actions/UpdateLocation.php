<?php

namespace App\Domain\ParkingLocation\Actions;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Models\User;
use App\Domain\ParkingLocation\Data\LocationData;
use App\Domain\ParkingLocation\Models\ParkingLocation;
use App\Support\Database\ChangeSet;
use Illuminate\Support\Facades\DB;

/**
 * Updates location attributes. The location code is immutable (it appears on reports and receipts).
 */
final class UpdateLocation
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(ParkingLocation $location, LocationData $data, ?User $actor): ParkingLocation
    {
        return DB::transaction(function () use ($location, $data, $actor) {
            $location = ParkingLocation::query()->lockForUpdate()->findOrFail($location->id);
            $location->fill($data->toAttributes());

            $changes = ChangeSet::of($location);
            if ($changes === []) {
                return $location;
            }

            $location->save();
            $this->audit->handle(AuditAction::LOCATION_CHANGED, $actor, 'parking_location', $location->id, ['changes' => $changes]);

            return $location;
        });
    }
}
