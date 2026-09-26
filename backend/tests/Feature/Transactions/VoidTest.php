<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\CashLedger\Enums\LedgerEntryType;
use App\Domain\CashLedger\Models\CashLedgerEntry;
use App\Domain\CashLedger\Services\CashBalances;
use App\Domain\Identity\Enums\Role;
use App\Domain\ParkingTransaction\Enums\TransactionStatus;
use App\Domain\ParkingTransaction\Enums\VoidRequestStatus;
use App\Domain\ParkingTransaction\Models\ParkingTransaction;
use App\Domain\ParkingTransaction\Models\VoidRequest;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    arrangeCashTest($this);
    $this->withoutVite();
    $this->payload = cashPayload();
    txApi('POST', '/api/v1/parking-transactions', $this->payload)->assertCreated();
    $this->tx = ParkingTransaction::sole();
    $this->supervisor = staffUser(Role::SUPERVISOR);
});

function requestVoidFromApp(string $uuid, string $reason = 'Salah pilih kendaraan')
{
    return txApi('POST', "/api/v1/parking-transactions/{$uuid}/void-request", ['reason' => $reason]);
}

it('lets the attendant request a void without touching money yet', function () {
    requestVoidFromApp($this->payload['transaction_uuid'])->assertCreated()->assertJsonPath('data.status', 'PENDING');

    expect($this->tx->refresh()->status)->toBe(TransactionStatus::VOID_REQUESTED)
        ->and(CashLedgerEntry::count())->toBe(1)
        ->and(auditOf(AuditAction::VOID_REQUESTED)->sole()->metadata['channel'])->toBe('MOBILE');
});

it('reverses the cash when a supervisor approves, keeping the original records', function () {
    requestVoidFromApp($this->payload['transaction_uuid']);
    $request = VoidRequest::sole();

    $this->actingAs($this->supervisor)
        ->put("/void-requests/{$request->id}", ['decision' => 'approve', 'decision_note' => 'OK'])
        ->assertSessionHasNoErrors();

    expect($this->tx->refresh()->status)->toBe(TransactionStatus::VOIDED)
        ->and($request->refresh()->status)->toBe(VoidRequestStatus::APPROVED)
        ->and($request->decided_by)->toBe($this->supervisor->id);

    [$cashIn, $reversal] = CashLedgerEntry::query()->orderBy('id')->get()->all();
    expect($cashIn->amount)->toBe(2000)
        ->and($reversal->type)->toBe(LedgerEntryType::REVERSAL)
        ->and($reversal->amount)->toBe(-2000)
        ->and($reversal->reverses_entry_id)->toBe($cashIn->id)
        ->and($reversal->balance_after)->toBe(0)
        ->and(app(CashBalances::class)->of($this->attendant->id))->toBe(0)
        ->and(auditOf(AuditAction::VOID_APPROVED)->sole()->metadata['reversal_entry_id'])->toBe($reversal->id);
});

it('restores the transaction when the supervisor rejects', function () {
    requestVoidFromApp($this->payload['transaction_uuid']);

    $this->actingAs($this->supervisor)->put('/void-requests/'.VoidRequest::sole()->id, ['decision' => 'reject', 'decision_note' => 'Bukti kurang']);

    expect($this->tx->refresh()->status)->toBe(TransactionStatus::COMPLETED)
        ->and(CashLedgerEntry::count())->toBe(1)
        ->and(auditOf(AuditAction::VOID_REJECTED))->toHaveCount(1);
});

it('lets operators request voids from the control center', function () {
    $operator = staffUser(Role::PARKING_OPERATOR);

    $this->actingAs($operator)->post("/transactions/{$this->tx->id}/void-request", ['reason' => 'Laporan warga'])->assertSessionHasNoErrors();

    expect(VoidRequest::sole()->channel->value)->toBe('ADMIN')
        ->and(VoidRequest::sole()->requested_by)->toBe($operator->id);
    $this->actingAs($operator)->put('/void-requests/'.VoidRequest::sole()->id, ['decision' => 'approve'])->assertForbidden();
});

it('refuses invalid void steps', function () {
    requestVoidFromApp($this->payload['transaction_uuid'])->assertCreated();
    requestVoidFromApp($this->payload['transaction_uuid'])->assertStatus(409)->assertJsonPath('error.details.field', 'reason');

    $request = VoidRequest::sole();
    $this->actingAs($this->supervisor)->put("/void-requests/{$request->id}", ['decision' => 'approve']);
    $this->actingAs($this->supervisor)->put("/void-requests/{$request->id}", ['decision' => 'reject'])->assertSessionHasErrors('decision_note');

    // A voided transaction cannot be voided again, and its reversal cannot be reversed.
    requestVoidFromApp($this->payload['transaction_uuid'])->assertStatus(409);
    expect(CashLedgerEntry::count())->toBe(2);
});

it('enforces four eyes in the database', function () {
    requestVoidFromApp($this->payload['transaction_uuid']);

    expect(fn () => DB::transaction(fn () => DB::table('void_requests')->update([
        'status' => 'APPROVED', 'decided_by' => DB::raw('requested_by'), 'decided_at' => now(),
    ])))->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('23514'));
});

it('restricts who may request and approve', function () {
    $this->actingAs(staffUser(Role::FINANCE))->post("/transactions/{$this->tx->id}/void-request", ['reason' => 'x'])->assertForbidden();
    $this->actingAs(staffUser(Role::SUPER_ADMIN))->post("/transactions/{$this->tx->id}/void-request", ['reason' => 'x'])->assertForbidden();
});
