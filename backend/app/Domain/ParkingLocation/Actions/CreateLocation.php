<?php

namespace App\Domain\ParkingLocation\Actions;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Models\User;
use App\Domain\ParkingLocation\Data\LocationData;
use App\Domain\ParkingLocation\Enums\LocationStatus;
use App\Domain\ParkingLocation\Models\ParkingLocation;
use Illuminate\Support\Facades\DB;

final class CreateLocation
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(string $locationCode, LocationData $data, ?User $actor): ParkingLocation
    {
        return DB::transaction(function () use ($locationCode, $data, $actor) {
            $location = ParkingLocation::create([
                'location_code' => strtoupper(trim($locationCode)),
                ...$data->toAttributes(),
                'status' => LocationStatus::ACTIVE,
            ]);

            $this->audit->handle(AuditAction::LOCATION_CREATED, $actor, 'parking_location', $location->id, [
                'location_code' => $location->location_code,
                'name' => $location->name,
                'location_type' => $location->location_type->value,
                'geofence_radius_m' => $location->geofence_radius_m,
            ]);

            return $location;
        });
    }
}
