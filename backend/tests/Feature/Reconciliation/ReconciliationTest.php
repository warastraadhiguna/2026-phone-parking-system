<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\CashSettlement\Models\CashSettlement;
use App\Domain\Identity\Enums\Role;
use App\Domain\ParkingTransaction\Models\ParkingTransaction;
use App\Domain\ParkingTransaction\Models\VoidRequest;
use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Internal\Gateways\FakePaymentGateway;
use App\Domain\Payment\Models\Payment;
use App\Domain\Reconciliation\Actions\RunReconciliation;
use App\Domain\Reconciliation\Enums\LineDimension;
use App\Domain\Reconciliation\Models\ReconciliationRun;
use App\Support\Errors\RuleViolation;
use App\Support\Time\BusinessTime;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Daily reconciliation (master doc §14; ADR-0012): transactions vs payments vs ledger vs settlements.
*/

beforeEach(function () {
    arrangeCashTest($this);
    syncRoles();
    $this->finance = staffUser(Role::FINANCE);
    $this->supervisor = staffUser(Role::SUPERVISOR);
    $this->today = now(BusinessTime::timezone())->toDateString();
});

function qrisPaid(string $status = 'settlement'): Payment
{
    $uuid = txApi('POST', '/api/v1/parking-transactions/qris', cashPayload(['payment_method' => 'QRIS']))->assertCreated()->json('data.payment.payment_uuid');
    $payment = Payment::where('payment_uuid', $uuid)->sole();
    /** @var FakePaymentGateway $gateway */
    $gateway = app(PaymentGatewayInterface::class);
    app('auth')->forgetGuards();
    test()->postJson('/api/v1/payments/webhooks/fake', $gateway->simulate($payment->provider_order_id, $status))->assertOk();

    return $payment->refresh();
}

function voidTransaction(object $test, ParkingTransaction $tx): void
{
    txApi('POST', "/api/v1/parking-transactions/{$tx->transaction_uuid}/void-request", ['reason' => 'Salah input'])->assertCreated();
    $test->actingAs($test->supervisor)->put('/void-requests/'.VoidRequest::where('transaction_id', $tx->id)->sole()->id, ['decision' => 'approve', 'decision_note' => 'OK']);
    app('auth')->forgetGuards();
}

function reconcile(): ReconciliationRun
{
    return app(RunReconciliation::class)->handle(test()->today);
}

it('reports expected, deposited and outstanding cash, QRIS expected vs paid, and revenue (§14)', function () {
    // Cash: 3 × 2000, one voided → expected 4000, voided 2000.
    $cash = collect(range(1, 3))->map(fn () => txApi('POST', '/api/v1/parking-transactions', cashPayload())->assertCreated()->json('data.transaction.transaction_uuid'));
    voidTransaction($this, ParkingTransaction::where('transaction_uuid', $cash[0])->sole());

    // QRIS: one paid, one expired (cancelled transaction, no money).
    qrisPaid('settlement');
    qrisPaid('expire');

    // Settlement: 3000 of the 4000 held.
    txApi('POST', '/api/v1/settlements', ['settlement_uuid' => (string) Str::uuid(), 'amount' => 3000])->assertCreated();
    $this->actingAs($this->finance)->put('/settlements/'.CashSettlement::sole()->id.'/decision', ['decision' => 'verify', 'verified_amount' => 3000]);

    $run = reconcile();

    expect($run->only(ReconciliationRun::METRICS))->toBe([
        'transaction_count' => 5,
        'expected_cash' => 4000,
        'voided_cash' => 2000,
        'ledger_cash_in' => 6000,
        'ledger_reversals' => -2000,
        'cash_deposited' => 3000,
        'cash_outstanding' => 1000,
        'qris_expected' => 2000,
        'qris_paid' => 2000,
        'qris_refunded' => 0,
        'qris_difference' => 0,
        'qris_open' => 0,
        'total_revenue' => 6000,
    ])->and($run->mismatch_count)->toBe(0)->and($run->error_count)->toBe(0);

    $attendantLine = $run->lines()->where('dimension', LineDimension::ATTENDANT->value)->sole();
    $locationLine = $run->lines()->where('dimension', LineDimension::LOCATION->value)->sole();
    expect($attendantLine->cash_outstanding)->toBe(1000)
        ->and($attendantLine->dimension_id)->toBe($this->attendant->id)
        ->and($locationLine->total_revenue)->toBe(6000)
        ->and($locationLine->cash_outstanding)->toBeNull()
        ->and(auditOf(AuditAction::RECONCILIATION_RUN)->count())->toBe(1);
});

it('flags money received for voided or cancelled QRIS transactions until refunded', function () {
    $payment = qrisPaid('settlement');
    voidTransaction($this, $payment->transaction);

    $run = reconcile();
    expect($run->qris_expected)->toBe(0)->and($run->qris_paid)->toBe(2000)->and($run->qris_difference)->toBe(2000)
        ->and($run->mismatches()->pluck('code')->map->value->all())->toBe(['QRIS_PAID_VOIDED_NOT_REFUNDED'])
        ->and($run->error_count)->toBe(0);

    // Finance returns the money: the difference disappears in a new run; the old run is unchanged.
    $this->actingAs($this->finance)->post("/payments/{$payment->id}/refunds", ['amount' => 2000, 'reason' => 'Dikembalikan tunai', 'refunded_at' => now()->subMinute()->format('Y-m-d H:i')])->assertSessionHasNoErrors();
    $second = reconcile();

    expect($second->qris_difference)->toBe(0)->and($second->mismatch_count)->toBe(0)
        ->and($run->refresh()->qris_difference)->toBe(2000)
        ->and(ReconciliationRun::count())->toBe(2);
});

it('detects integrity breaches made outside the application', function () {
    txApi('POST', '/api/v1/parking-transactions', cashPayload())->assertCreated();

    // A cash transaction inserted directly, without its ledger cash-in.
    DB::statement(<<<'SQL'
        INSERT INTO parking_transactions (transaction_uuid, transaction_number, shift_id, attendant_id, location_id, device_id, sync_sequence,
            vehicle_type, charged_tariff_amount, payment_method, status, transaction_time_device, transaction_time_server, geofence_result,
            payload_hash, created_at, updated_at)
        SELECT gen_random_uuid(), 'TRX-INJECTED', shift_id, attendant_id, location_id, device_id, 999999, vehicle_type, 5000, 'CASH', 'COMPLETED',
            now(), now(), 'UNKNOWN', payload_hash, now(), now()
        FROM parking_transactions LIMIT 1
        SQL);
    // And a tampered derived balance.
    DB::update('UPDATE attendant_cash_balances SET balance = balance + 777');

    $run = reconcile();
    $codes = $run->mismatches()->pluck('code')->map->value->sort()->values()->all();

    expect($codes)->toBe(['BALANCE_DRIFT', 'CASH_WITHOUT_LEDGER'])
        ->and($run->error_count)->toBe(2)
        ->and($run->mismatches()->where('code', 'CASH_WITHOUT_LEDGER')->sole()->reference)->toBe('TRX-INJECTED');

    $this->artisan('reconciliation:run', ['date' => $this->today])->assertFailed();
});

it('stores runs as immutable history', function () {
    txApi('POST', '/api/v1/parking-transactions', cashPayload())->assertCreated();
    DB::update('UPDATE attendant_cash_balances SET balance = balance + 1'); // yields one mismatch row too
    $run = reconcile();
    expect($run->lines()->count())->toBe(2)->and($run->mismatches()->count())->toBe(1);

    foreach (['UPDATE reconciliation_runs SET total_revenue = 1', 'DELETE FROM reconciliation_runs', 'UPDATE reconciliation_lines SET total_revenue = 1', 'DELETE FROM reconciliation_mismatches'] as $sql) {
        try {
            DB::transaction(fn () => DB::statement($sql));
            $this->fail("Expected refusal: {$sql}");
        } catch (QueryException $e) {
            expect($e->getCode())->toBe('23001');
        }
    }
    expect($run->refresh()->total_revenue)->toBe(2000);
});

it('refuses future dates', function () {
    expect(fn () => app(RunReconciliation::class)->handle(now(BusinessTime::timezone())->addDays(2)->toDateString()))->toThrow(RuleViolation::class);
});

it('lets Finance run it, others with access only read it', function () {
    txApi('POST', '/api/v1/parking-transactions', cashPayload())->assertCreated();

    $this->actingAs($this->finance)->post('/reconciliation', ['business_date' => $this->today])->assertRedirect();
    $run = ReconciliationRun::sole();
    expect($run->run_by)->toBe($this->finance->id);

    $this->actingAs(staffUser(Role::AUDITOR))->post('/reconciliation', ['business_date' => $this->today])->assertForbidden();
    $this->actingAs(staffUser(Role::AUDITOR))->get("/reconciliation/{$run->id}")->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Reconciliation/Show')->where('run.expected_cash', 2000)->has('attendants', 1)->has('locations', 1));
    $this->actingAs(staffUser(Role::AUDITOR))->get('/reconciliation')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('can.run', false)->has('runs.data', 1));
    $this->actingAs(staffUser(Role::EXECUTIVE_VIEWER))->get('/reconciliation')->assertForbidden();
});
