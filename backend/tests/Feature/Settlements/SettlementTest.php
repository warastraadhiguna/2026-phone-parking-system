<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\CashLedger\Enums\LedgerEntryType;
use App\Domain\CashLedger\Models\CashLedgerEntry;
use App\Domain\CashLedger\Services\CashBalances;
use App\Domain\CashSettlement\Enums\SettlementStatus;
use App\Domain\CashSettlement\Models\CashSettlement;
use App\Domain\Identity\Enums\Role;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Cash settlement (master doc §11, §13, Scenario D; ADR-0011).
*/

beforeEach(function () {
    arrangeCashTest($this);
    foreach (range(1, 3) as $i) {
        txApi('POST', '/api/v1/parking-transactions', cashPayload())->assertCreated();
    }
    syncRoles();
    $this->finance = staffUser(Role::FINANCE);
});

function submitSettlement(array $overrides = []): TestResponse
{
    return txApi('POST', '/api/v1/settlements', ['settlement_uuid' => (string) Str::uuid(), 'amount' => 5000, 'shift_uuid' => TX_SHIFT_UUID, ...$overrides]);
}

function decide(object $test, CashSettlement $settlement, array $data): TestResponse
{
    return $test->actingAs($test->finance)->put("/settlements/{$settlement->id}/decision", $data);
}

it('keeps money in place until finance verifies the counted amount (Scenario D)', function () {
    submitSettlement(['amount' => 5000])
        ->assertCreated()
        ->assertJsonPath('data.settlement.status', 'SUBMITTED')
        ->assertJsonPath('data.settlement.balance_at_submission', 6000)
        ->assertJsonPath('data.cash_balance', 6000);

    $settlement = CashSettlement::sole();
    expect($settlement->settlement_number)->toMatch('/^STL-\d{6}-\d{8}$/')
        ->and(CashLedgerEntry::where('type', 'SETTLEMENT_OUT')->count())->toBe(0);

    decide($this, $settlement, ['decision' => 'verify', 'verified_amount' => 5000])->assertSessionHasNoErrors();

    $settlement->refresh();
    $out = CashLedgerEntry::where('type', LedgerEntryType::SETTLEMENT_OUT->value)->sole();
    expect($settlement->status)->toBe(SettlementStatus::VERIFIED)
        ->and($settlement->verified_amount)->toBe(5000)
        ->and($settlement->decided_by)->toBe($this->finance->id)
        ->and($out->amount)->toBe(-5000)
        ->and($out->settlement_id)->toBe($settlement->id)
        ->and($out->balance_after)->toBe(1000)
        ->and(app(CashBalances::class)->of($this->attendant->id))->toBe(1000)
        ->and(auditOf(AuditAction::SETTLEMENT_VERIFIED)->sole()->metadata['ledger_entry_id'])->toBe($out->id);

    txApi('GET', '/api/v1/cash/summary')->assertOk()
        ->assertJsonPath('data.cash_balance', 1000)
        ->assertJsonPath('data.total.collected', 6000)
        ->assertJsonPath('data.total.deposited', 5000)
        ->assertJsonPath('data.total.outstanding', 1000)
        ->assertJsonPath('data.today.deposited', 5000)
        ->assertJsonPath('data.open_shift.collected', 6000)
        ->assertJsonPath('data.pending_settlement', null);

    $this->artisan('cash:verify-balances')->assertSuccessful();
});

it('books only the counted amount; the rest stays outstanding and needs a note', function () {
    submitSettlement(['amount' => 6000])->assertCreated();
    $settlement = CashSettlement::sole();

    decide($this, $settlement, ['decision' => 'verify', 'verified_amount' => 5500])->assertSessionHasErrors('decision_note');
    expect($settlement->refresh()->status)->toBe(SettlementStatus::SUBMITTED);

    decide($this, $settlement, ['decision' => 'verify', 'verified_amount' => 5500, 'decision_note' => 'Kurang Rp500 saat dihitung'])->assertSessionHasNoErrors();

    expect($settlement->refresh()->verified_amount)->toBe(5500)
        ->and(app(CashBalances::class)->of($this->attendant->id))->toBe(500)
        ->and(auditOf(AuditAction::SETTLEMENT_VERIFIED)->sole()->metadata['difference'])->toBe(-500);
});

it('never takes more cash than the attendant holds', function () {
    submitSettlement(['amount' => 7000])->assertStatus(422)->assertJsonPath('error.code', 'SETTLEMENT_INVALID')->assertJsonPath('error.details.cash_balance', 6000);

    submitSettlement(['amount' => 6000])->assertCreated();
    decide($this, CashSettlement::sole(), ['decision' => 'verify', 'verified_amount' => 6500, 'decision_note' => 'Lebih'])->assertSessionHasErrors('verified_amount');

    expect(CashLedgerEntry::where('type', 'SETTLEMENT_OUT')->count())->toBe(0);
});

it('is idempotent on settlement_uuid and allows one open submission at a time', function () {
    $body = ['settlement_uuid' => (string) Str::uuid(), 'amount' => 3000, 'shift_uuid' => TX_SHIFT_UUID];
    txApi('POST', '/api/v1/settlements', $body)->assertCreated();
    txApi('POST', '/api/v1/settlements', $body)->assertOk()->assertJsonPath('data.replayed', true);
    txApi('POST', '/api/v1/settlements', [...$body, 'amount' => 3001])->assertStatus(409)->assertJsonPath('error.code', 'SYNC_CONFLICT');

    submitSettlement(['amount' => 1000])->assertStatus(422)->assertJsonPath('error.code', 'SETTLEMENT_INVALID')
        ->assertJsonPath('error.details.open_settlement_uuid', $body['settlement_uuid']);

    txApi('POST', "/api/v1/settlements/{$body['settlement_uuid']}/cancel")->assertOk()->assertJsonPath('data.settlement.status', 'CANCELLED');
    txApi('POST', "/api/v1/settlements/{$body['settlement_uuid']}/cancel")->assertStatus(409);
    submitSettlement(['amount' => 1000])->assertCreated();

    expect(CashSettlement::count())->toBe(2)->and(auditOf(AuditAction::SETTLEMENT_CANCELLED)->count())->toBe(1);
});

it('rejects without moving money, with a reason', function () {
    submitSettlement()->assertCreated();
    $settlement = CashSettlement::sole();

    decide($this, $settlement, ['decision' => 'reject'])->assertSessionHasErrors('decision_note');
    decide($this, $settlement, ['decision' => 'reject', 'decision_note' => 'Uang tidak diserahkan'])->assertSessionHasNoErrors();

    expect($settlement->refresh()->status)->toBe(SettlementStatus::REJECTED)
        ->and(app(CashBalances::class)->of($this->attendant->id))->toBe(6000)
        ->and(CashLedgerEntry::where('type', 'SETTLEMENT_OUT')->count())->toBe(0);

    // A decided settlement cannot be decided again.
    decide($this, $settlement, ['decision' => 'verify', 'verified_amount' => 5000])->assertSessionHasErrors('decision_note');
});

it('lets only Finance decide, never the submitter', function () {
    submitSettlement()->assertCreated();
    $settlement = CashSettlement::sole();

    $this->actingAs(staffUser(Role::SUPERVISOR))->put("/settlements/{$settlement->id}/decision", ['decision' => 'verify', 'verified_amount' => 5000])->assertForbidden();
    $this->actingAs(staffUser(Role::AUDITOR))->get("/settlements/{$settlement->id}")->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('can.decide', false));

    // Four eyes in the database as well.
    expect(fn () => DB::transaction(fn () => DB::update(
        "UPDATE cash_settlements SET status = 'REJECTED', decided_by = submitted_by, decided_at = now(), decision_note = 'x' WHERE id = ?", [$settlement->id],
    )))->toThrow(QueryException::class, 'cash_settlements_four_eyes_check');
});

it('freezes decided settlements and the settlement ledger entry, even through direct SQL', function () {
    submitSettlement()->assertCreated();
    $settlement = CashSettlement::sole();
    decide($this, $settlement, ['decision' => 'verify', 'verified_amount' => 5000])->assertSessionHasNoErrors();

    foreach ([
        ['UPDATE cash_settlements SET verified_amount = 1 WHERE id = ?', [$settlement->id]],
        ["UPDATE cash_settlements SET status = 'CANCELLED' WHERE id = ?", [$settlement->id]],
        ['DELETE FROM cash_settlements WHERE id = ?', [$settlement->id]],
        ["UPDATE cash_ledger_entries SET amount = -1 WHERE type = 'SETTLEMENT_OUT'", []],
    ] as [$sql, $bindings]) {
        try {
            DB::transaction(fn () => DB::statement($sql, $bindings));
            $this->fail("Expected refusal: {$sql}");
        } catch (QueryException $e) {
            expect($e->getCode())->toBe('23001');
        }
    }
});

it('refuses changing a submission and a second settlement-out for one settlement', function () {
    submitSettlement()->assertCreated();
    $settlement = CashSettlement::sole();

    expect(fn () => DB::transaction(fn () => DB::update('UPDATE cash_settlements SET amount = 1 WHERE id = ?', [$settlement->id])))
        ->toThrow(QueryException::class, 'immutable');

    decide($this, $settlement, ['decision' => 'verify', 'verified_amount' => 1000])->assertSessionHasErrors('decision_note');
    decide($this, $settlement, ['decision' => 'verify', 'verified_amount' => 5000])->assertSessionHasNoErrors();
    expect(fn () => DB::transaction(fn () => DB::insert(
        "INSERT INTO cash_ledger_entries (attendant_id, settlement_id, type, amount, balance_after, created_at) VALUES (?, ?, 'SETTLEMENT_OUT', -1, 999, now())",
        [$settlement->attendant_id, $settlement->id],
    )))->toThrow(QueryException::class, 'cash_ledger_one_settlement_out');
    expect(fn () => DB::transaction(fn () => DB::insert(
        "INSERT INTO cash_ledger_entries (attendant_id, type, amount, balance_after, created_at, settlement_id) VALUES (?, 'SETTLEMENT_OUT', -1, -1, now(), NULL)",
        [$settlement->attendant_id],
    )))->toThrow(QueryException::class);
});

it('stores an optional proof photo privately, shown only to settlement viewers', function () {
    Storage::fake('local');
    txApi('POST', '/api/v1/settlements', [
        'settlement_uuid' => (string) Str::uuid(), 'amount' => 2000,
        'proof' => UploadedFile::fake()->image('slip.jpg', 400, 300),
    ])->assertCreated()->assertJsonPath('data.settlement.has_proof', true);

    $settlement = CashSettlement::sole();
    Storage::disk('local')->assertExists((string) $settlement->proof_path);
    $this->actingAs($this->finance)->get("/settlements/{$settlement->id}/proof")->assertOk();
    $this->actingAs(staffUser(Role::EXECUTIVE_VIEWER))->get("/settlements/{$settlement->id}/proof")->assertForbidden();
});

it('shows pending submissions and outstanding cash per attendant in the Control Center', function () {
    submitSettlement(['amount' => 4000])->assertCreated();

    $this->actingAs($this->finance)->get('/settlements')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Settlements/Index')
            ->where('totals.outstanding', 6000)
            ->where('totals.pending_count', 1)
            ->where('totals.pending_amount', 4000)
            ->where('outstanding.0.balance', 6000)
            ->has('settlements.data', 1));

    txApi('GET', '/api/v1/cash/summary')->assertJsonPath('data.pending_settlement.amount', 4000);
    txApi('GET', '/api/v1/settlements')->assertOk()->assertJsonCount(1, 'data');
});
