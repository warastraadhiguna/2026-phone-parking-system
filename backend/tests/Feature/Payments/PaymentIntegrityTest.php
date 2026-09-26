<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Enums\Role;
use App\Domain\Payment\Actions\RecordManualRefund;
use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Internal\Gateways\FakePaymentGateway;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentAdjustment;
use App\Support\Errors\RuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Database guarantees for payments and manual refunds (ADR-0006, Q3).
*/

beforeEach(function () {
    arrangeCashTest($this);
    txApi('POST', '/api/v1/parking-transactions/qris', cashPayload(['payment_method' => 'QRIS']))->assertCreated();
    $this->payment = Payment::sole();
});

function payNow(): void
{
    /** @var FakePaymentGateway $gateway */
    $gateway = app(PaymentGatewayInterface::class);
    test()->postJson('/api/v1/payments/webhooks/fake', $gateway->simulate(Payment::sole()->provider_order_id))->assertOk();
}

function sqlFails(string $sql, array $bindings, string $sqlState): void
{
    try {
        DB::transaction(fn () => DB::statement($sql, $bindings));
        test()->fail("Expected SQLSTATE {$sqlState}: {$sql}");
    } catch (QueryException $e) {
        expect($e->getCode())->toBe($sqlState);
    }
}

it('freezes identity and amount and keeps PAID final, even through direct SQL', function () {
    payNow();
    $id = $this->payment->id;

    sqlFails('UPDATE payments SET amount = 1 WHERE id = ?', [$id], '23001');
    sqlFails('UPDATE payments SET provider_reference = ? WHERE id = ?', ['other', $id], '23001');
    sqlFails("UPDATE payments SET status = 'PENDING', paid_at = NULL WHERE id = ?", [$id], '23001');
    sqlFails("UPDATE payments SET status = 'CANCELLED', paid_at = NULL WHERE id = ?", [$id], '23001');
    sqlFails('DELETE FROM payments WHERE id = ?', [$id], '23001');
    sqlFails('TRUNCATE payments CASCADE', [], '23001');

    expect($this->payment->refresh()->status)->toBe(PaymentStatus::PAID);
});

it('keeps provider events append-only', function () {
    sqlFails("UPDATE payment_provider_events SET outcome = 'APPLIED'", [], '23001');
    sqlFails('DELETE FROM payment_provider_events', [], '23001');
});

it('refuses QRIS transactions created offline or with a tariff difference', function () {
    sqlFails('UPDATE parking_transactions SET offline_created = true', [], '23001');
    expect(fn () => DB::transaction(fn () => DB::statement(
        "INSERT INTO parking_transactions (transaction_uuid, transaction_number, shift_id, attendant_id, location_id, device_id, sync_sequence, vehicle_type, charged_tariff_amount, server_expected_tariff_amount, tariff_difference_amount, payment_method, status, transaction_time_device, transaction_time_server, geofence_result, offline_created, payload_hash, created_at, updated_at)
         SELECT gen_random_uuid(), 'TRX-X', shift_id, attendant_id, location_id, device_id, 999, vehicle_type, 1000, 2000, 1000, 'QRIS', 'WAITING_PAYMENT', now(), now(), 'INSIDE', false, payload_hash, now(), now() FROM parking_transactions LIMIT 1"
    )))->toThrow(QueryException::class, 'parking_transactions_qris_check');
});

it('records a manual refund for a PAID payment without changing the payment (Q3)', function () {
    payNow();
    $finance = staffUser(Role::FINANCE);

    $refund = app(RecordManualRefund::class)->handle($this->payment, $finance, 2000, 'Pelanggan ditagih ganda', CarbonImmutable::now()->subMinute());

    expect($refund->amount)->toBe(2000)
        ->and($this->payment->refresh()->status)->toBe(PaymentStatus::PAID)
        ->and($this->payment->refundedAmount())->toBe(2000)
        ->and(auditOf(AuditAction::MANUAL_REFUND_RECORDED)->sole()->metadata['amount'])->toBe(2000);

    // Never more than was paid, and never editable.
    expect(fn () => app(RecordManualRefund::class)->handle($this->payment, $finance, 1, 'Lagi lagi', CarbonImmutable::now()))
        ->toThrow(RuleViolation::class);
    sqlFails('UPDATE payment_adjustments SET amount = 1', [], '23001');
    sqlFails('DELETE FROM payment_adjustments', [], '23001');
});

it('refuses refunds for unpaid payments and over-refunds, also in the database', function () {
    $finance = staffUser(Role::FINANCE);

    expect(fn () => app(RecordManualRefund::class)->handle($this->payment, $finance, 100, 'Belum dibayar', CarbonImmutable::now()))
        ->toThrow(RuleViolation::class);

    payNow();
    $insert = fn (int $amount) => DB::transaction(fn () => PaymentAdjustment::create([
        'adjustment_uuid' => (string) Str::uuid(), 'payment_id' => $this->payment->id, 'type' => 'MANUAL_REFUND',
        'amount' => $amount, 'reason' => 'Uji langsung', 'refunded_at' => now(), 'recorded_by' => $finance->id,
    ]));

    $insert(1500);
    expect(fn () => $insert(501))->toThrow(QueryException::class, 'would exceed');
});
