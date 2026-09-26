<?php

namespace Database\Seeders;

use App\Domain\Assignment\Actions\AssignAttendant;
use App\Domain\Identity\Actions\CreateUser;
use App\Domain\Identity\Actions\SyncRolePermissions;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\ParkingAttendant\Actions\RegisterAttendant;
use App\Domain\ParkingAttendant\Data\AttendantData;
use App\Domain\ParkingAttendant\Models\ParkingAttendant;
use App\Domain\ParkingLocation\Actions\CreateLocation;
use App\Domain\ParkingLocation\Data\LocationData;
use App\Domain\ParkingLocation\Enums\LocationType;
use App\Domain\ParkingLocation\Models\ParkingLocation;
use App\Domain\Tariff\Actions\ApproveTariff;
use App\Domain\Tariff\Actions\CreateTariffDraft;
use App\Domain\Tariff\Data\TariffData;
use App\Domain\Tariff\Enums\VehicleType;
use App\Domain\Tariff\Models\Tariff;
use App\Support\Time\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Local development data, created through the real Actions (so audit and rules apply):
 * roles, one demo staff account per role, demo locations, one demo attendant with an
 * assignment, and DEV-ONLY tariffs. Nothing here is official data.
 */
class DatabaseSeeder extends Seeder
{
    /** username => [name, role] */
    private const DEMO_STAFF = [
        'superadmin' => ['Demo Super Admin', Role::SUPER_ADMIN],
        'dishub' => ['Demo Admin Dishub', Role::DISHUB_ADMIN],
        'operator' => ['Demo Operator Parkir', Role::PARKING_OPERATOR],
        'keuangan' => ['Demo Keuangan', Role::FINANCE],
        'supervisor' => ['Demo Supervisor', Role::SUPERVISOR],
        'auditor' => ['Demo Auditor', Role::AUDITOR],
        'pimpinan' => ['Demo Pimpinan', Role::EXECUTIVE_VIEWER],
    ];

    private const DEV_TARIFF_REFERENCE = 'DEV-ONLY — bukan tarif resmi';

    public function run(SyncRolePermissions $sync): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('DatabaseSeeder creates demo data and only runs in local/testing.');
        }

        $sync->handle();

        $password = (string) config('identity.dev_seed_password');
        if ($password === '') {
            $this->command->warn('DEV_SEED_PASSWORD is empty: roles synced, demo data skipped.');

            return;
        }

        $this->seedStaff($password);
        $this->seedMasterData($password);
    }

    private function seedStaff(string $password): void
    {
        foreach (self::DEMO_STAFF as $username => [$name, $role]) {
            if (User::query()->where('username', $username)->exists()) {
                continue;
            }

            app(CreateUser::class)->handle($username, $name, null, $password, $role->accountType(), [$role], actor: null);
            $this->command->line("  demo account: {$username} ({$role->label()})");
        }
    }

    private function seedMasterData(string $password): void
    {
        $dishub = User::query()->where('username', 'dishub')->firstOrFail();
        $superAdmin = User::query()->where('username', 'superadmin')->firstOrFail();

        $alunAlun = $this->location('DEV-ALUN-01', new LocationData(
            'Alun-Alun Pati (demo)', 'Jl. Alun-Alun, Pati', '-6.7550000', '111.0380000', 50, LocationType::ON_STREET, 40, 10,
        ), $dishub);
        $this->location('DEV-PASAR-01', new LocationData(
            'Pasar Puri (demo)', 'Jl. Pasar Puri, Pati', '-6.7505000', '111.0415000', 75, LocationType::OFF_STREET, 120, 30,
        ), $dishub);

        if (! ParkingAttendant::query()->exists()) {
            $attendant = app(RegisterAttendant::class)->handle(
                new AttendantData('Demo Juru Parkir', '3318000000000001', '081200000001', BusinessTime::today(), null),
                $password,
                $dishub,
            );
            app(AssignAttendant::class)->handle($attendant, $alunAlun, BusinessTime::today(), null, $dishub);
            $this->command->line("  demo attendant: {$attendant->attendant_code} (login: ".strtolower($attendant->attendant_code).')');
        }

        if (! Tariff::query()->exists()) {
            // Four-eyes: drafted by Dishub, approved by Super Admin. Effective a few seconds from now.
            $from = CarbonImmutable::now()->addSeconds(5);
            foreach (LocationType::cases() as $locationType) {
                foreach ([VehicleType::MOTORCYCLE->value => 2000, VehicleType::CAR->value => 5000, VehicleType::OTHER->value => 1000] as $vehicle => $amount) {
                    $draft = app(CreateTariffDraft::class)->handle(
                        new TariffData(VehicleType::from($vehicle), $locationType, null, $amount, $from, self::DEV_TARIFF_REFERENCE),
                        $dishub,
                    );
                    app(ApproveTariff::class)->handle($draft, $superAdmin);
                }
            }
            $this->command->line('  demo tariffs: DEV-ONLY amounts for every location type');
        }
    }

    private function location(string $code, LocationData $data, User $actor): ParkingLocation
    {
        return ParkingLocation::query()->where('location_code', $code)->first()
            ?? app(CreateLocation::class)->handle($code, $data, $actor);
    }
}
