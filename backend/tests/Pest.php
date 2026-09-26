<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Device\Enums\DeviceStatus;
use App\Domain\Device\Models\Device;
use App\Domain\Identity\Actions\SyncRolePermissions;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\ParkingAttendant\Enums\AttendantStatus;
use App\Domain\ParkingAttendant\Models\ParkingAttendant;
use App\Domain\ParkingLocation\Enums\LocationStatus;
use App\Domain\ParkingLocation\Enums\LocationType;
use App\Domain\ParkingLocation\Models\ParkingLocation;
use App\Support\Time\BusinessTime;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
| Feature tests boot the Laravel application and run against the real PostgreSQL
| test database (see phpunit.xml). Tests that need migrations use RefreshDatabase.
*/

pest()->extend(TestCase::class)->in('Feature');
pest()->use(RefreshDatabase::class)->in('Feature/Identity', 'Feature/Audit', 'Feature/MasterData', 'Feature/Operations', 'Feature/Transactions', 'Feature/Payments', 'Feature/Settlements', 'Feature/Reconciliation', 'Feature/Reviews', 'Feature/Reporting');

require_once __DIR__.'/Support/TransactionHelpers.php';

/** A fixed device for mobile tests. */
const TEST_DEVICE_UUID = '7d0c7a0e-3b7e-4c1e-9d55-4f7a2b1c9e01';

const OTHER_DEVICE_UUID = '0b8f4c2d-6a1e-4f3b-8c9d-2e7a5b1f0c33';

function syncRoles(): void
{
    app(SyncRolePermissions::class)->handle();
}

function staffUser(Role ...$roles): User
{
    return User::factory()->staff(...($roles ?: [Role::SUPER_ADMIN]))->create();
}

/** An attendant login account with its registry record (no device yet). */
function attendantUser(): User
{
    $user = User::factory()->attendant()->create();
    makeAttendantRecord($user);

    return $user;
}

function makeAttendantRecord(User $user, AttendantStatus $status = AttendantStatus::ACTIVE): ParkingAttendant
{
    $seq = (int) DB::scalar("SELECT nextval('parking_attendant_code_seq')");

    return ParkingAttendant::create([
        'attendant_code' => sprintf('JP-%06d', $seq),
        'user_id' => $user->id,
        'name' => $user->name,
        'identity_number' => str_pad((string) random_int(1, 9_999_999_999), 16, '3318', STR_PAD_LEFT),
        'phone' => '0812'.random_int(10_000_000, 99_999_999),
        'status' => $status,
        'registered_at' => BusinessTime::today(),
    ]);
}

function attendantOf(User $user): ParkingAttendant
{
    return ParkingAttendant::query()->where('user_id', $user->id)->sole();
}

function makeDevice(ParkingAttendant $attendant, string $uuid = TEST_DEVICE_UUID, DeviceStatus $status = DeviceStatus::ACTIVE): Device
{
    return Device::create([
        'device_uuid' => $uuid,
        'attendant_id' => $attendant->id,
        'status' => $status,
        'registered_at' => now(),
        'approved_at' => $status === DeviceStatus::ACTIVE ? now() : null,
        'deactivated_at' => $status->isFinal() ? now() : null,
    ]);
}

function makeLocation(array $overrides = []): ParkingLocation
{
    static $n = 0;
    $n++;

    return ParkingLocation::create([
        'location_code' => 'LOC-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT).'-'.random_int(100, 999),
        'name' => "Lokasi Uji {$n}",
        'address' => 'Jl. Uji, Pati',
        'latitude' => '-6.7550000',
        'longitude' => '111.0380000',
        'geofence_radius_m' => 50,
        'location_type' => LocationType::ON_STREET,
        'status' => LocationStatus::ACTIVE,
        'motorcycle_capacity' => 20,
        'car_capacity' => 5,
        ...$overrides,
    ]);
}

/** @return Collection<int, AuditLog> */
function auditOf(AuditAction $action): Collection
{
    return AuditLog::query()->where('action', $action->value)->orderBy('id')->get();
}
