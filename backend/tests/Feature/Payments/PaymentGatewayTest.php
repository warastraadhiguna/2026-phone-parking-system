<?php

use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Data\QrisChargeRequest;
use App\Domain\Payment\Enums\GatewayStatus;
use App\Domain\Payment\Exceptions\GatewayUnavailable;
use App\Domain\Payment\Exceptions\InvalidWebhook;
use App\Domain\Payment\Exceptions\UnsafePaymentConfiguration;
use App\Domain\Payment\Internal\Gateways\FakePaymentGateway;
use App\Domain\Payment\Internal\Gateways\MidtransPaymentGateway;
use App\Domain\Payment\Services\PaymentGatewayResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
| Gateway guardrails (ADR-0006) and the Midtrans adapter against faked HTTP responses shaped like
| the Midtrans Core API documentation (checked 2026-09-25).
*/

const SANDBOX = 'https://api.sandbox.midtrans.com';
const TEST_SERVER_KEY = 'SB-Mid-server-TEST-ONLY';

function midtrans(): MidtransPaymentGateway
{
    return new MidtransPaymentGateway(SANDBOX, TEST_SERVER_KEY, 'gopay', 5);
}

function chargeRequest(): QrisChargeRequest
{
    return new QrisChargeRequest('ord-1', 2000, 'MOTORCYCLE', 'Parkir Motor TRX-1', CarbonImmutable::parse('2026-09-25 08:00:00', 'Asia/Jakarta'), 15);
}

/** @return array<string, mixed> */
function paymentConfig(array $overrides = []): array
{
    return array_replace_recursive(config('payment'), $overrides);
}

describe('guardrails', function () {
    it('never allows the fake gateway in production', function () {
        expect(fn () => PaymentGatewayResolver::assertSafeConfiguration('production', paymentConfig(['gateway' => 'fake'])))
            ->toThrow(UnsafePaymentConfiguration::class, 'never be active in production');

        app()->detectEnvironment(fn () => 'production');
        config(['payment.gateway' => 'fake']);
        app()->forgetScopedInstances();
        expect(fn () => app(PaymentGatewayInterface::class))->toThrow(UnsafePaymentConfiguration::class);
    });

    it('allows Midtrans production only in production with explicit approval', function () {
        $prod = ['gateway' => 'midtrans', 'midtrans' => ['environment' => 'production']];

        expect(fn () => PaymentGatewayResolver::assertSafeConfiguration('local', paymentConfig([...$prod, 'midtrans' => ['environment' => 'production', 'production_approved' => true]])))
            ->toThrow(UnsafePaymentConfiguration::class);
        expect(fn () => PaymentGatewayResolver::assertSafeConfiguration('production', paymentConfig($prod)))
            ->toThrow(UnsafePaymentConfiguration::class);

        PaymentGatewayResolver::assertSafeConfiguration('production', paymentConfig(['gateway' => 'midtrans', 'midtrans' => ['environment' => 'production', 'production_approved' => true]]));
        PaymentGatewayResolver::assertSafeConfiguration('production', paymentConfig(['gateway' => 'midtrans', 'midtrans' => ['environment' => 'sandbox']]));
        expect(true)->toBeTrue();
    });

    it('refuses unknown gateway names instead of falling back', function () {
        expect(fn () => PaymentGatewayResolver::assertSafeConfiguration('local', paymentConfig(['gateway' => 'other'])))
            ->toThrow(UnsafePaymentConfiguration::class);
    });

    it('uses the fake gateway in tests and Midtrans sandbox when configured', function () {
        expect(app(PaymentGatewayInterface::class))->toBeInstanceOf(FakePaymentGateway::class);

        config(['payment.gateway' => 'midtrans', 'payment.midtrans.server_key' => TEST_SERVER_KEY]);
        app()->forgetScopedInstances();
        expect(app(PaymentGatewayInterface::class))->toBeInstanceOf(MidtransPaymentGateway::class);
    });
});

describe('Midtrans adapter', function () {
    it('sends a QRIS charge with Basic auth, amount and custom expiry, and reads the QR', function () {
        Http::fake([SANDBOX.'/v2/charge' => Http::response([
            'status_code' => '201', 'status_message' => 'QRIS transaction is created',
            'transaction_id' => 'mid-tx-1', 'order_id' => 'ord-1', 'gross_amount' => '2000.00',
            'payment_type' => 'qris', 'transaction_status' => 'pending', 'fraud_status' => 'accept',
            'qr_string' => '00020101021226...', 'expiry_time' => '2026-09-25 08:15:00',
            'actions' => [['name' => 'generate-qr-code', 'method' => 'GET', 'url' => SANDBOX.'/v2/qris/mid-tx-1/qr-code']],
        ])]);

        $result = midtrans()->createQrisCharge(chargeRequest());

        expect($result->accepted)->toBeTrue()
            ->and($result->providerReference)->toBe('mid-tx-1')
            ->and($result->qrString)->toBe('00020101021226...')
            ->and($result->qrImageUrl)->toBe(SANDBOX.'/v2/qris/mid-tx-1/qr-code')
            ->and($result->expiresAt?->utc()->toIso8601String())->toBe('2026-09-25T01:15:00+00:00')
            // Must already be UTC: Eloquent would store a WIB instance 7 hours off.
            ->and($result->expiresAt?->getTimezone()->getName())->toBe('UTC');

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Basic '.base64_encode(TEST_SERVER_KEY.':'))
                && $request['payment_type'] === 'qris'
                && $request['transaction_details'] === ['order_id' => 'ord-1', 'gross_amount' => 2000]
                && $request['qris'] === ['acquirer' => 'gopay']
                && $request['custom_expiry'] === ['order_time' => '2026-09-25 08:00:00 +0700', 'expiry_duration' => 15, 'unit' => 'minute'];
        });
    });

    it('reports a refused charge, and an unknown outcome on server errors or timeouts', function () {
        Http::fakeSequence(SANDBOX.'/v2/charge')
            ->push(['status_code' => '406', 'status_message' => 'Duplicate order ID'])
            ->push('oops', 502)
            ->push(['status_code' => '500', 'status_message' => 'Internal'])
            ->pushFailedConnection();

        $refused = midtrans()->createQrisCharge(chargeRequest());
        expect($refused->accepted)->toBeFalse()->and($refused->rejectReason)->toContain('406');

        expect(fn () => midtrans()->createQrisCharge(chargeRequest()))->toThrow(GatewayUnavailable::class);
        expect(fn () => midtrans()->createQrisCharge(chargeRequest()))->toThrow(GatewayUnavailable::class);
        expect(fn () => midtrans()->createQrisCharge(chargeRequest()))->toThrow(GatewayUnavailable::class);
    });

    it('maps provider statuses conservatively', function (array $body, GatewayStatus $expected) {
        Http::fake([SANDBOX.'/v2/ord-1/status' => Http::response(['order_id' => 'ord-1', 'gross_amount' => '2000.00', ...$body])]);

        expect(midtrans()->getStatus('ord-1')->status)->toBe($expected);
    })->with([
        'settlement' => [['status_code' => '200', 'transaction_status' => 'settlement'], GatewayStatus::PAID],
        'settlement, fraud deny' => [['status_code' => '200', 'transaction_status' => 'settlement', 'fraud_status' => 'deny'], GatewayStatus::FAILED],
        'capture challenge' => [['status_code' => '200', 'transaction_status' => 'capture', 'fraud_status' => 'challenge'], GatewayStatus::PENDING],
        'pending' => [['status_code' => '201', 'transaction_status' => 'pending'], GatewayStatus::PENDING],
        'expire' => [['status_code' => '407', 'transaction_status' => 'expire'], GatewayStatus::EXPIRED],
        'cancel' => [['status_code' => '200', 'transaction_status' => 'cancel'], GatewayStatus::CANCELLED],
        'refund' => [['status_code' => '200', 'transaction_status' => 'refund'], GatewayStatus::REFUNDED],
        'not found' => [['status_code' => '404', 'status_message' => "Transaction doesn't exist."], GatewayStatus::NOT_FOUND],
        'strange' => [['status_code' => '200', 'transaction_status' => 'authorize'], GatewayStatus::UNKNOWN],
    ]);

    it('asks for the status when a cancel is refused because the charge is final', function () {
        Http::fake([
            SANDBOX.'/v2/ord-1/cancel' => Http::response(['status_code' => '412', 'status_message' => 'Merchant cannot modify the status of the transaction']),
            SANDBOX.'/v2/ord-1/status' => Http::response(['status_code' => '200', 'order_id' => 'ord-1', 'transaction_status' => 'settlement', 'gross_amount' => '2000.00']),
        ]);

        expect(midtrans()->cancel('ord-1')->status)->toBe(GatewayStatus::PAID);
    });

    it('verifies the notification signature and never keeps the signature', function () {
        $payload = [
            'order_id' => 'ord-1', 'status_code' => '200', 'gross_amount' => '2000.00', 'transaction_status' => 'settlement',
            'transaction_id' => 'mid-tx-1', 'fraud_status' => 'accept', 'settlement_time' => '2026-09-25 08:05:00',
        ];
        $payload['signature_key'] = hash('sha512', 'ord-1'.'200'.'2000.00'.TEST_SERVER_KEY);

        $status = midtrans()->verifyAndParseWebhook($payload);
        expect($status->status)->toBe(GatewayStatus::PAID)
            ->and($status->grossAmount)->toBe(2000)
            ->and($status->paidAt?->utc()->toIso8601String())->toBe('2026-09-25T01:05:00+00:00')
            ->and($status->eventKey)->toHaveLength(64)
            ->and($status->raw)->not->toHaveKey('signature_key');

        expect(fn () => midtrans()->verifyAndParseWebhook([...$payload, 'gross_amount' => '20000.00']))->toThrow(InvalidWebhook::class);
        expect(fn () => (new MidtransPaymentGateway(SANDBOX, 'another-key', 'gopay', 5))->verifyAndParseWebhook($payload))->toThrow(InvalidWebhook::class);
        expect(fn () => midtrans()->verifyAndParseWebhook(['order_id' => 'ord-1']))->toThrow(InvalidWebhook::class);
    });

    it('never guesses amounts', function () {
        expect(MidtransPaymentGateway::wholeRupiah('2000.00'))->toBe(2000)
            ->and(MidtransPaymentGateway::wholeRupiah('2000'))->toBe(2000)
            ->and(MidtransPaymentGateway::wholeRupiah('2000.50'))->toBeNull()
            ->and(MidtransPaymentGateway::wholeRupiah('-1'))->toBeNull()
            ->and(MidtransPaymentGateway::wholeRupiah(null))->toBeNull();
    });

    it('fails safely without a configured server key', function () {
        $gateway = new MidtransPaymentGateway(SANDBOX, null, 'gopay', 5);

        expect(fn () => $gateway->createQrisCharge(chargeRequest()))->toThrow(GatewayUnavailable::class)
            ->and(fn () => $gateway->verifyAndParseWebhook(['order_id' => 'a', 'status_code' => '200', 'gross_amount' => '1.00', 'signature_key' => 'x', 'transaction_status' => 'settlement']))
            ->toThrow(InvalidWebhook::class);
    });
});
