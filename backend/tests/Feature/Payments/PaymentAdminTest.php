<?php

use App\Domain\Identity\Enums\Role;
use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Internal\Gateways\FakePaymentGateway;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentAdjustment;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    arrangeCashTest($this);
    txApi('POST', '/api/v1/parking-transactions/qris', cashPayload(['payment_method' => 'QRIS']))->assertCreated();
    /** @var FakePaymentGateway $gateway */
    $gateway = app(PaymentGatewayInterface::class);
    $this->postJson('/api/v1/payments/webhooks/fake', $gateway->simulate(Payment::sole()->provider_order_id))->assertOk();
    $this->payment = Payment::sole();
    app('auth')->forgetGuards();
});

it('lists payments with received, refunded and net totals', function () {
    $this->actingAs(staffUser(Role::AUDITOR))->get('/payments')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Payments/Index')
            ->where('totals.received', 2000)->where('totals.refunded', 0)->where('totals.net', 2000)
            ->has('payments.data', 1)->where('payments.data.0.status.value', 'PAID'));
});

it('shows the provider answers and lets only Finance record a manual refund', function () {
    $this->actingAs(staffUser(Role::SUPERVISOR))->get("/payments/{$this->payment->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Payments/Show')->where('can.recordRefund', false)->has('events', 2));

    $this->actingAs(staffUser(Role::SUPERVISOR))->post("/payments/{$this->payment->id}/refunds", ['amount' => 2000, 'reason' => 'Ditagih dua kali', 'refunded_at' => now()->subMinute()->format('Y-m-d H:i')])
        ->assertForbidden();

    $finance = staffUser(Role::FINANCE);
    $this->actingAs($finance)->get("/payments/{$this->payment->id}")
        ->assertInertia(fn (Assert $page) => $page->where('can.recordRefund', true));
    $this->actingAs($finance)->post("/payments/{$this->payment->id}/refunds", ['amount' => 2000, 'reason' => 'Ditagih dua kali', 'refunded_at' => now()->subMinute()->format('Y-m-d H:i')])
        ->assertSessionHasNoErrors();

    expect(PaymentAdjustment::sole()->amount)->toBe(2000)->and($this->payment->refresh()->status)->toBe(PaymentStatus::PAID);

    $this->actingAs($finance)->post("/payments/{$this->payment->id}/refunds", ['amount' => 1, 'reason' => 'Sekali lagi', 'refunded_at' => now()->format('Y-m-d H:i')])
        ->assertSessionHasErrors('amount');
    $this->actingAs($finance)->get('/payments')->assertInertia(fn (Assert $page) => $page->where('totals.net', 0));
});

it('shows the payment on the transaction page', function () {
    $this->actingAs(staffUser(Role::FINANCE))->get("/transactions/{$this->payment->transaction_id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('payment.status.value', 'PAID')->where('payment.amount', 2000));
});

it('hides payments from roles without payments.view', function () {
    $this->actingAs(staffUser(Role::EXECUTIVE_VIEWER))->get('/payments')->assertForbidden();
});
