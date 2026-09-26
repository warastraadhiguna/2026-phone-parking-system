<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Enums\Role;
use App\Domain\ParkingLocation\Enums\LocationType;
use App\Domain\Tariff\Actions\ApproveTariff;
use App\Domain\Tariff\Actions\CreateTariffDraft;
use App\Domain\Tariff\Data\TariffData;
use App\Domain\Tariff\Enums\TariffStatus;
use App\Domain\Tariff\Enums\VehicleType;
use App\Domain\Tariff\Models\Tariff;
use App\Domain\Tariff\Services\TariffResolver;
use App\Support\Errors\ApiException;
use App\Support\Time\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    syncRoles();
    $this->withoutVite();
    $this->maker = staffUser(Role::DISHUB_ADMIN);
    $this->checker = staffUser(Role::DISHUB_ADMIN);
    $this->location = makeLocation(['location_type' => LocationType::ON_STREET]);
});

function localIn(string $modify): string
{
    return CarbonImmutable::now(BusinessTime::timezone())->modify($modify)->format('Y-m-d\TH:i');
}

/** @return array<string, mixed> */
function tariffPayload(array $overrides = []): array
{
    return [
        'vehicle_type' => 'MOTORCYCLE',
        'location_type' => 'ON_STREET',
        'location_id' => '',
        'amount' => 2000,
        'effective_from' => localIn('+1 day'),
        'regulation_reference' => 'Perda Uji No. 1/2026',
        ...$overrides,
    ];
}

/** Creates and approves a tariff directly (bypassing HTTP) with an effective start. */
function approvedTariff(object $test, int $amount, CarbonImmutable $from, ?int $locationId = null, VehicleType $vehicle = VehicleType::MOTORCYCLE): Tariff
{
    $draft = app(CreateTariffDraft::class)->handle(new TariffData($vehicle, LocationType::ON_STREET, $locationId, $amount, $from, 'Uji'), $test->maker);
    // Approval requires a future start; pretend we approve before it.
    test()->travelTo($from->subMinute());
    $tariff = app(ApproveTariff::class)->handle($draft, $test->checker);
    test()->travelBack();

    return $tariff->refresh();
}

it('creates a draft that has no effect until approved', function () {
    $this->actingAs($this->maker)->post('/tariffs', tariffPayload())->assertRedirect()->assertSessionHas('success');

    $tariff = Tariff::sole();
    expect($tariff->status)->toBe(TariffStatus::DRAFT)
        ->and($tariff->amount)->toBe(2000)
        ->and($tariff->created_by)->toBe($this->maker->id)
        // The form sends WIB; storage is UTC.
        ->and($tariff->effective_from->utc()->format('Y-m-d H:i'))->toBe(BusinessTime::fromLocal(tariffPayload()['effective_from'])->format('Y-m-d H:i'));

    expect(app(TariffResolver::class)->find(VehicleType::MOTORCYCLE, $this->location, CarbonImmutable::now()->addDays(2)))->toBeNull();
    expect(auditOf(AuditAction::TARIFF_CREATED))->toHaveCount(1);
});

it('requires a second person to approve (four eyes, also in the database)', function () {
    $this->actingAs($this->maker)->post('/tariffs', tariffPayload());
    $tariff = Tariff::sole();

    $this->actingAs($this->maker)->put("/tariffs/{$tariff->id}/approve")
        ->assertSessionHasErrors(['tariff' => 'Tarif harus disetujui oleh pengguna lain (bukan pembuatnya).']);

    expect(fn () => DB::transaction(fn () => DB::table('tariffs')->where('id', $tariff->id)->update([
        'status' => 'APPROVED', 'approved_by' => $this->maker->id, 'approved_at' => now(),
    ])))->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('23514'));

    $this->actingAs($this->checker)->put("/tariffs/{$tariff->id}/approve")->assertSessionHasNoErrors();
    expect($tariff->refresh()->status)->toBe(TariffStatus::APPROVED)
        ->and($tariff->approved_by)->toBe($this->checker->id)
        ->and(auditOf(AuditAction::TARIFF_APPROVED))->toHaveCount(1);
});

it('never approves a tariff that would apply retroactively', function () {
    $this->actingAs($this->maker)->post('/tariffs', tariffPayload(['effective_from' => localIn('+1 hour')]));
    $tariff = Tariff::sole();

    $this->travel(2)->hours();

    $this->actingAs($this->checker)->put("/tariffs/{$tariff->id}/approve")->assertSessionHasErrors('effective_from');
    expect($tariff->refresh()->status)->toBe(TariffStatus::DRAFT);
});

it('supersedes the running tariff of the same scope at the new start', function () {
    $old = approvedTariff($this, 2000, CarbonImmutable::now()->addDay());
    $newStart = CarbonImmutable::now()->addDays(10);
    $new = approvedTariff($this, 3000, $newStart);

    // Timestamps are stored to the second: compare with the stored start.
    expect($old->refresh()->effective_until->equalTo($new->effective_from))->toBeTrue()
        ->and(auditOf(AuditAction::TARIFF_APPROVED)->last()->metadata['supersedes_tariff_id'])->toBe($old->id);

    $resolver = app(TariffResolver::class);
    expect($resolver->resolve(VehicleType::MOTORCYCLE, $this->location, $newStart->subSecond())->id)->toBe($old->id)
        ->and($resolver->resolve(VehicleType::MOTORCYCLE, $this->location, $newStart)->id)->toBe($new->id);
});

it('blocks approval when a later-starting tariff of the same scope is already approved', function () {
    approvedTariff($this, 3000, CarbonImmutable::now()->addDays(10));
    $draft = app(CreateTariffDraft::class)->handle(
        new TariffData(VehicleType::MOTORCYCLE, LocationType::ON_STREET, null, 2500, CarbonImmutable::now()->addDays(5), 'Uji'),
        $this->maker,
    );

    $this->actingAs($this->checker)->put("/tariffs/{$draft->id}/approve")->assertSessionHasErrors('effective_from');
});

it('prefers a location-specific tariff over the location-type tariff', function () {
    $start = CarbonImmutable::now()->addDay();
    $general = approvedTariff($this, 2000, $start);
    $specific = approvedTariff($this, 1000, $start, $this->location->id);
    $elsewhere = makeLocation(['location_type' => LocationType::ON_STREET]);

    $resolver = app(TariffResolver::class);
    $at = $start->addHour();
    expect($resolver->resolve(VehicleType::MOTORCYCLE, $this->location, $at)->id)->toBe($specific->id)
        ->and($resolver->resolve(VehicleType::MOTORCYCLE, $elsewhere, $at)->id)->toBe($general->id);
});

it('reports TARIFF_NOT_FOUND when nothing applies', function () {
    approvedTariff($this, 2000, CarbonImmutable::now()->addDay());

    expect(fn () => app(TariffResolver::class)->resolve(VehicleType::CAR, $this->location, CarbonImmutable::now()->addDays(2)))
        ->toThrow(fn (ApiException $e) => expect($e->errorCode->value)->toBe('TARIFF_NOT_FOUND'));
    expect(app(TariffResolver::class)->find(VehicleType::MOTORCYCLE, $this->location, CarbonImmutable::now()))->toBeNull();
});

it('freezes approved tariffs in the database', function (string $sql) {
    $tariff = approvedTariff($this, 2000, CarbonImmutable::now()->addDay());

    expect(fn () => DB::transaction(fn () => DB::statement(str_replace(':id', (string) $tariff->id, $sql))))
        ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('23001'));
    expect($tariff->refresh()->amount)->toBe(2000);
})->with([
    'change amount' => 'UPDATE tariffs SET amount = 1 WHERE id = :id',
    'change start' => 'UPDATE tariffs SET effective_from = now() WHERE id = :id',
    'delete' => 'DELETE FROM tariffs WHERE id = :id',
    'truncate' => 'TRUNCATE tariffs CASCADE', // CASCADE: other tables reference tariffs
]);

it('prevents overlapping approved tariffs of one scope in the database', function () {
    approvedTariff($this, 2000, CarbonImmutable::now()->addDay());

    expect(fn () => DB::transaction(fn () => Tariff::create([
        'vehicle_type' => 'MOTORCYCLE', 'location_type' => 'ON_STREET', 'amount' => 999,
        'effective_from' => now()->addDays(3), 'regulation_reference' => 'x', 'status' => 'APPROVED',
        'created_by' => $this->maker->id, 'approved_by' => $this->checker->id, 'approved_at' => now(),
    ])))->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('23P01'));
});

it('edits and rejects only drafts', function () {
    $approved = approvedTariff($this, 2000, CarbonImmutable::now()->addDay());
    $this->actingAs($this->maker)->put("/tariffs/{$approved->id}", tariffPayload(['amount' => 1]))->assertSessionHasErrors('amount');
    $this->actingAs($this->checker)->put("/tariffs/{$approved->id}/reject", ['reason' => 'x'])->assertSessionHasErrors('reason');

    $this->actingAs($this->maker)->post('/tariffs', tariffPayload(['vehicle_type' => 'CAR']));
    $draft = Tariff::query()->where('status', 'DRAFT')->sole();
    $this->actingAs($this->maker)->put("/tariffs/{$draft->id}", tariffPayload(['vehicle_type' => 'CAR', 'amount' => 6000]))->assertSessionHasNoErrors();
    expect($draft->refresh()->amount)->toBe(6000)->and(auditOf(AuditAction::TARIFF_CHANGED))->toHaveCount(1);

    $this->actingAs($this->checker)->put("/tariffs/{$draft->id}/reject", ['reason' => 'Belum ada Perda'])->assertSessionHasNoErrors();
    expect($draft->refresh()->status)->toBe(TariffStatus::REJECTED)->and($draft->rejection_reason)->toBe('Belum ada Perda');
});

it('checks that a location-specific tariff matches the location type', function () {
    $this->actingAs($this->maker)
        ->post('/tariffs', tariffPayload(['location_type' => 'OFF_STREET', 'location_id' => $this->location->id]))
        ->assertSessionHasErrors('location_id');
});

it('restricts who may create and approve tariffs', function () {
    $this->actingAs(staffUser(Role::PARKING_OPERATOR))->post('/tariffs', tariffPayload())->assertForbidden();
    $this->actingAs($this->maker)->post('/tariffs', tariffPayload());
    $draft = Tariff::sole();
    $this->actingAs(staffUser(Role::FINANCE))->put("/tariffs/{$draft->id}/approve")->assertForbidden();
    $this->actingAs(staffUser(Role::FINANCE))->get('/tariffs')->assertOk();
    $this->actingAs(staffUser(Role::EXECUTIVE_VIEWER))->get('/tariffs')->assertForbidden();
});
