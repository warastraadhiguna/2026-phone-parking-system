<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\CashLedger\Models\CashLedgerEntry;
use App\Domain\Identity\Enums\Role;
use App\Domain\ParkingTransaction\Enums\TransactionStatus;
use App\Domain\ParkingTransaction\Models\ParkingTransaction;
use App\Domain\ParkingTransaction\Models\VoidRequest;
use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Enums\ProviderEventOutcome;
use App\Domain\Payment\Enums\ProviderEventSource;
use App\Domain\Payment\Internal\Gateways\FakePaymentGateway;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentProviderEvent;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
| QRIS end to end with the fake provider (master doc §16–§19, Scenario B; ADR-0006).
*/

beforeEach(fn () => arrangeCashTest($this));

function fakeGateway(): FakePaymentGateway
{
    $gateway = app(PaymentGatewayInterface::class);
    expect($gateway)->toBeInstanceOf(FakePaymentGateway::class);

    return $gateway;
}

/** @return array<string, mixed> */
function qrisPayload(array $overrides = []): array
{
    return cashPayload(['payment_method' => 'QRIS', ...$overrides]);
}

function startQris(array $overrides = []): TestResponse
{
    return txApi('POST', '/api/v1/parking-transactions/qris', qrisPayload($overrides));
}

function webhook(array $payload): TestResponse
{
    app('auth')->forgetGuards();

    return test()->postJson('/api/v1/payments/webhooks/fake', $payload);
}

/** The provider says the customer paid (or another status). */
function providerEvent(string $status = 'settlement', ?int $amount = null): TestResponse
{
    return webhook(fakeGateway()->simulate(Payment::sole()->provider_order_id, $status, $amount));
}

it('shows a dynamic QR and completes the transaction only on a verified provider confirmation (Scenario B)', function () {
    $response = startQris()
        ->assertCreated()
        ->assertJsonPath('data.transaction.status', 'WAITING_PAYMENT')
        ->assertJsonPath('data.transaction.payment_method', 'QRIS')
        ->assertJsonPath('data.transaction.charged_amount', 2000)
        ->assertJsonPath('data.payment.status', 'PENDING')
        ->assertJsonPath('data.payment.amount', 2000);

    expect($response->json('data.payment.qr_string'))->toStartWith('FAKE-QRIS|')
        ->and($response->json('data.payment.expires_at'))->not->toBeNull();

    // Polling before payment: still waiting. The app never decides.
    $uuid = $response->json('data.payment.payment_uuid');
    txApi('GET', "/api/v1/payments/{$uuid}")->assertOk()->assertJsonPath('data.payment.status', 'PENDING');

    providerEvent('settlement')->assertOk()->assertJsonPath('data.outcome', 'APPLIED');

    $payment = Payment::sole();
    expect($payment->status)->toBe(PaymentStatus::PAID)
        ->and($payment->paid_at)->not->toBeNull()
        ->and(ParkingTransaction::sole()->status)->toBe(TransactionStatus::COMPLETED);

    txApi('GET', "/api/v1/payments/{$uuid}")->assertOk()
        ->assertJsonPath('data.payment.status', 'PAID')
        ->assertJsonPath('data.payment.qr_string', null)
        ->assertJsonPath('data.transaction.status', 'COMPLETED');

    // QRIS money is not cash held by the attendant.
    expect(CashLedgerEntry::count())->toBe(0);
    expect(auditOf(AuditAction::PAYMENT_CREATED)->count())->toBe(1)
        ->and(auditOf(AuditAction::PAYMENT_PAID)->sole()->metadata['amount'])->toBe(2000);
});

it('returns the same transaction and QR when the request is retried (idempotent)', function () {
    $payload = qrisPayload();
    $first = txApi('POST', '/api/v1/parking-transactions/qris', $payload)->assertCreated();
    $second = txApi('POST', '/api/v1/parking-transactions/qris', $payload)->assertOk()->assertJsonPath('data.replayed', true);

    expect($second->json('data.payment.payment_uuid'))->toBe($first->json('data.payment.payment_uuid'))
        ->and($second->json('data.payment.qr_string'))->toBe($first->json('data.payment.qr_string'))
        ->and(ParkingTransaction::count())->toBe(1)
        ->and(Payment::count())->toBe(1)
        ->and(PaymentProviderEvent::where('source', 'CHARGE')->count())->toBe(1);

    txApi('POST', '/api/v1/parking-transactions/qris', [...$payload, 'vehicle_plate' => 'K1AB'])
        ->assertStatus(409)->assertJsonPath('error.code', 'SYNC_CONFLICT');
});

it('processes a resent notification only once', function () {
    startQris()->assertCreated();
    $notification = fakeGateway()->simulate(Payment::sole()->provider_order_id, 'settlement');

    webhook($notification)->assertOk()->assertJsonPath('data.outcome', 'APPLIED');
    webhook($notification)->assertOk()->assertJsonPath('data.outcome', 'DUPLICATE');

    expect(PaymentProviderEvent::where('source', 'WEBHOOK')->count())->toBe(1)
        ->and(auditOf(AuditAction::PAYMENT_PAID)->count())->toBe(1);
});

it('rejects notifications that are not authentic and stores nothing', function () {
    startQris()->assertCreated();
    $notification = fakeGateway()->notification(Payment::sole()->provider_order_id, 'settlement', 2000);

    webhook([...$notification, 'signature_key' => str_repeat('a', 128)])->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
    // A tampered amount breaks the signature too.
    webhook([...$notification, 'gross_amount' => '1.00'])->assertForbidden();
    webhook(['order_id' => 'x'])->assertForbidden();

    expect(Payment::sole()->status)->toBe(PaymentStatus::PENDING)
        ->and(PaymentProviderEvent::where('source', 'WEBHOOK')->count())->toBe(0);
});

it('only exposes the webhook of the active provider', function () {
    test()->postJson('/api/v1/payments/webhooks/midtrans', [])->assertNotFound();
});

it('never marks PAID when the provider reports another amount', function () {
    startQris()->assertCreated();

    providerEvent('settlement', 1500)->assertOk()->assertJsonPath('data.outcome', 'AMOUNT_MISMATCH');

    expect(Payment::sole()->status)->toBe(PaymentStatus::PENDING)
        ->and(ParkingTransaction::sole()->status)->toBe(TransactionStatus::WAITING_PAYMENT)
        ->and(ParkingTransaction::sole()->review_flags)->toContain('PAYMENT_AMOUNT_MISMATCH')
        ->and(auditOf(AuditAction::PAYMENT_AMOUNT_MISMATCH)->count())->toBe(1);
});

it('never moves a PAID payment backwards (delayed or out-of-order notifications)', function () {
    startQris()->assertCreated();
    providerEvent('settlement')->assertOk();

    $orderId = Payment::sole()->provider_order_id;
    foreach (['pending', 'expire', 'cancel', 'deny'] as $status) {
        webhook(fakeGateway()->notification($orderId, $status, 2000))->assertOk()->assertJsonPath('data.outcome', 'NO_CHANGE');
    }

    expect(Payment::sole()->status)->toBe(PaymentStatus::PAID)
        ->and(ParkingTransaction::sole()->status)->toBe(TransactionStatus::COMPLETED);
});

it('expires an unpaid QR from the provider status and cancels the transaction (§19)', function () {
    startQris()->assertCreated();
    $this->travel(16)->minutes();

    $this->artisan('payments:check-pending')->assertSuccessful();

    expect(Payment::sole()->status)->toBe(PaymentStatus::EXPIRED)
        ->and(ParkingTransaction::sole()->status)->toBe(TransactionStatus::CANCELLED)
        ->and(auditOf(AuditAction::PAYMENT_EXPIRED)->count())->toBe(1);
});

it('does not expire anything on its own while the provider is unreachable', function () {
    startQris()->assertCreated();
    $this->travel(16)->minutes();
    fakeGateway()->setDown(true);

    $this->artisan('payments:check-pending')->assertSuccessful();
    txApi('GET', '/api/v1/payments/'.Payment::sole()->payment_uuid)->assertOk()->assertJsonPath('data.payment.status', 'PENDING');

    expect(Payment::sole()->status)->toBe(PaymentStatus::PENDING);
});

it('accepts a late confirmation: the payment becomes PAID and the cancelled transaction is flagged', function () {
    startQris()->assertCreated();
    providerEvent('expire')->assertOk();
    expect(ParkingTransaction::sole()->status)->toBe(TransactionStatus::CANCELLED);

    providerEvent('settlement')->assertOk()->assertJsonPath('data.outcome', 'APPLIED');

    $tx = ParkingTransaction::sole();
    expect(Payment::sole()->status)->toBe(PaymentStatus::PAID)
        ->and($tx->status)->toBe(TransactionStatus::CANCELLED)
        ->and($tx->review_flags)->toContain('LATE_PAYMENT')
        ->and(auditOf(AuditAction::PAYMENT_LATE_PAID)->count())->toBe(1);
});

it('requires the current server tariff, an open shift and an online request', function () {
    startQris(['charged_amount' => 1500])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'TARIFF_CHANGED')
        ->assertJsonPath('error.details.expected_amount', 2000);

    startQris(['offline_created' => true])->assertUnprocessable();
    startQris(['shift_uuid' => (string) Str::uuid()])->assertStatus(409)->assertJsonPath('error.code', 'SHIFT_NOT_ACTIVE');

    expect(ParkingTransaction::count())->toBe(0)->and(Payment::count())->toBe(0);
});

it('cancels the transaction when the provider refuses the charge', function () {
    fakeGateway()->failNextCharge('reject');

    startQris()->assertCreated()->assertJsonPath('data.payment.status', 'FAILED')->assertJsonPath('data.transaction.status', 'CANCELLED');

    expect(PaymentProviderEvent::sole()->outcome)->toBe(ProviderEventOutcome::REJECTED)
        ->and(auditOf(AuditAction::PAYMENT_FAILED)->count())->toBe(1);
});

it('keeps an unknown charge outcome open and recovers it on retry', function () {
    fakeGateway()->failNextCharge('unavailable');
    $payload = qrisPayload();

    txApi('POST', '/api/v1/parking-transactions/qris', $payload)
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE')
        ->assertJsonPath('error.details.retryable', true);
    expect(Payment::sole()->status)->toBe(PaymentStatus::CREATED)
        ->and(ParkingTransaction::sole()->status)->toBe(TransactionStatus::WAITING_PAYMENT);

    // Retry with the same data: the provider does not know the order, so it is charged now.
    txApi('POST', '/api/v1/parking-transactions/qris', $payload)->assertOk()
        ->assertJsonPath('data.replayed', true)
        ->assertJsonPath('data.payment.status', 'PENDING');

    expect(Payment::sole()->charge_attempts)->toBe(2);
});

it('lets the attendant withdraw an unpaid QR, but never cancels a paid one', function () {
    $uuid = startQris()->json('data.payment.payment_uuid');

    txApi('POST', "/api/v1/payments/{$uuid}/cancel")->assertOk()
        ->assertJsonPath('data.payment.status', 'CANCELLED')
        ->assertJsonPath('data.transaction.status', 'CANCELLED');
    expect(auditOf(AuditAction::PAYMENT_CANCEL_REQUESTED)->count())->toBe(1);

    // Customer paid just before the cancel reached the provider: the provider's answer wins.
    $second = startQris()->json('data.payment.payment_uuid');
    fakeGateway()->simulate(Payment::where('payment_uuid', $second)->value('provider_order_id'), 'settlement');
    txApi('POST', "/api/v1/payments/{$second}/cancel")->assertOk()->assertJsonPath('data.payment.status', 'PAID');

    txApi('POST', "/api/v1/payments/{$second}/cancel")->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT');
});

it('answers only about the attendant\'s own payments', function () {
    txApi('GET', '/api/v1/payments/'.Str::uuid())->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    app('auth')->forgetGuards();
    test()->getJson('/api/v1/payments/'.Str::uuid())->assertUnauthorized();
});

it('records verified notifications for unknown orders without touching anything', function () {
    webhook(fakeGateway()->notification('someone-else-order', 'settlement', 5000))->assertOk()->assertJsonPath('data.outcome', 'UNKNOWN_PAYMENT');

    $event = PaymentProviderEvent::sole();
    expect($event->payment_id)->toBeNull()->and($event->source)->toBe(ProviderEventSource::WEBHOOK)
        ->and($event->payload)->not->toHaveKey('signature_key');
});

it('keeps a voided QRIS payment PAID and writes no cash reversal (Q3)', function () {
    startQris()->assertCreated();
    providerEvent('settlement')->assertOk();
    $tx = ParkingTransaction::sole();

    txApi('POST', "/api/v1/parking-transactions/{$tx->transaction_uuid}/void-request", ['reason' => 'Salah input'])->assertCreated();
    syncRoles();
    $supervisor = staffUser(Role::SUPERVISOR);
    $this->actingAs($supervisor)->put('/void-requests/'.VoidRequest::sole()->id, ['decision' => 'approve', 'decision_note' => 'OK']);

    expect($tx->refresh()->status)->toBe(TransactionStatus::VOIDED)
        ->and(Payment::sole()->status)->toBe(PaymentStatus::PAID)
        ->and(CashLedgerEntry::count())->toBe(0);
});
