<?php

namespace App\Domain\Payment\Internal\Gateways;

use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Data\ChargeResult;
use App\Domain\Payment\Data\ProviderStatus;
use App\Domain\Payment\Data\QrisChargeRequest;
use App\Domain\Payment\Enums\GatewayStatus;
use App\Domain\Payment\Enums\PaymentProvider;
use App\Domain\Payment\Exceptions\GatewayUnavailable;
use App\Domain\Payment\Exceptions\InvalidWebhook;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;

/**
 * In-process stand-in for a QRIS provider. LOCAL DEVELOPMENT AND TESTS ONLY: the resolver refuses
 * it in production (ADR-0006).
 *
 * It mimics Midtrans closely enough to exercise the real flow: order ids, expiry, notifications
 * signed like Midtrans (SHA512(order_id + status_code + gross_amount + key)) and the same webhook
 * path. State lives in the cache. A "customer payment" is simulated with simulate().
 */
final class FakePaymentGateway implements PaymentGatewayInterface
{
    private const PREFIX = 'fake-payment:';

    private const CONTROL = 'fake-payment-control:next-charge';

    public function __construct(
        private readonly Repository $cache,
        private readonly string $signingKey,
    ) {}

    public function provider(): PaymentProvider
    {
        return PaymentProvider::FAKE;
    }

    public function createQrisCharge(QrisChargeRequest $request): ChargeResult
    {
        $mode = $this->cache->pull(self::CONTROL);
        if ($mode === 'unavailable') {
            throw new GatewayUnavailable('Fake gateway: simulated outage.');
        }
        if ($mode === 'reject') {
            return ChargeResult::rejected('Fake gateway: simulated rejection.', ['status_code' => '400']);
        }
        if ($this->state($request->orderId) !== null) {
            return ChargeResult::rejected('406 Duplicate order_id.', ['status_code' => '406']);
        }

        $reference = (string) Str::uuid();
        $expiresAt = $request->orderTime->addMinutes($request->expiryMinutes);
        $this->put($request->orderId, [
            'status' => 'pending',
            'amount' => $request->amount,
            'reference' => $reference,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);

        return ChargeResult::accepted(
            $reference,
            "FAKE-QRIS|{$request->orderId}|{$request->amount}",
            null,
            $expiresAt,
            ['status_code' => '201', 'transaction_id' => $reference, 'transaction_status' => 'pending', 'gross_amount' => $request->amount.'.00'],
        );
    }

    public function getStatus(string $orderId): ProviderStatus
    {
        $this->failIfDown();
        $state = $this->state($orderId);
        if ($state === null) {
            return new ProviderStatus($orderId, GatewayStatus::NOT_FOUND);
        }

        if ($state['status'] === 'pending' && CarbonImmutable::parse($state['expires_at'])->isPast()) {
            $state['status'] = 'expire';
            $this->put($orderId, $state);
        }

        return $this->toStatus($orderId, $state);
    }

    public function cancel(string $orderId): ProviderStatus
    {
        $current = $this->getStatus($orderId);
        $state = $this->state($orderId);
        if ($state !== null && $state['status'] === 'pending') {
            $state['status'] = 'cancel';
            $this->put($orderId, $state);

            return $this->toStatus($orderId, $state);
        }

        return $current;
    }

    public function verifyAndParseWebhook(array $payload): ProviderStatus
    {
        foreach (['order_id', 'status_code', 'gross_amount', 'signature_key', 'transaction_status'] as $field) {
            if (! is_string($payload[$field] ?? null) || $payload[$field] === '') {
                throw new InvalidWebhook("Missing field {$field}.");
            }
        }
        $expected = $this->signature((string) $payload['order_id'], (string) $payload['status_code'], (string) $payload['gross_amount']);
        if (! hash_equals($expected, (string) $payload['signature_key'])) {
            throw new InvalidWebhook('Signature mismatch.');
        }

        $status = $this->toStatus((string) $payload['order_id'], [
            'status' => $payload['transaction_status'],
            'reference' => $payload['transaction_id'] ?? null,
            'gross' => $payload['gross_amount'],
        ]);

        return new ProviderStatus(
            $status->orderId, $status->status, $status->providerReference, $status->grossAmount, $status->paidAt, $status->raw,
            hash('sha256', implode('|', ['FAKE', $payload['order_id'], $payload['transaction_id'] ?? '', $payload['transaction_status'], $payload['status_code']])),
        );
    }

    /**
     * Simulates what happens at the provider (e.g. the customer pays) and returns the signed
     * notification the provider would send. Development tooling and tests only.
     *
     * @return array<string, string>
     */
    public function simulate(string $orderId, string $transactionStatus = 'settlement', ?int $grossAmount = null): array
    {
        $state = $this->state($orderId) ?? throw new \InvalidArgumentException("Unknown fake order {$orderId}.");
        $state['status'] = $transactionStatus;
        $this->put($orderId, $state);

        return $this->notification($orderId, $transactionStatus, $grossAmount ?? (int) $state['amount'], (string) $state['reference']);
    }

    /** @return array<string, string> a correctly signed notification (tests may alter it afterwards) */
    public function notification(string $orderId, string $transactionStatus, int $grossAmount, string $reference = 'fake-ref'): array
    {
        $statusCode = match ($transactionStatus) {
            'settlement', 'capture', 'cancel', 'expire' => '200',
            'pending' => '201',
            default => '202',
        };
        $gross = $grossAmount.'.00';

        return [
            'transaction_time' => now()->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s'),
            'transaction_status' => $transactionStatus,
            'transaction_id' => $reference,
            'status_code' => $statusCode,
            'signature_key' => $this->signature($orderId, $statusCode, $gross),
            'payment_type' => 'qris',
            'order_id' => $orderId,
            'gross_amount' => $gross,
            'fraud_status' => 'accept',
            'currency' => 'IDR',
        ];
    }

    /** Makes the next charge fail: "reject" (provider refuses) or "unavailable" (outcome unknown). */
    public function failNextCharge(string $mode): void
    {
        $this->cache->put(self::CONTROL, $mode, 3600);
    }

    /** Simulates a provider outage for status calls until cleared. */
    public function setDown(bool $down): void
    {
        $down ? $this->cache->put(self::CONTROL.':down', true, 3600) : $this->cache->forget(self::CONTROL.':down');
    }

    private function failIfDown(): void
    {
        if ($this->cache->get(self::CONTROL.':down') === true) {
            throw new GatewayUnavailable('Fake gateway: simulated outage.');
        }
    }

    private function signature(string $orderId, string $statusCode, string $gross): string
    {
        return hash('sha512', $orderId.$statusCode.$gross.$this->signingKey);
    }

    /** @param  array<string, mixed>  $state */
    private function toStatus(string $orderId, array $state): ProviderStatus
    {
        $status = match ($state['status']) {
            'settlement', 'capture' => GatewayStatus::PAID,
            'pending' => GatewayStatus::PENDING,
            'expire' => GatewayStatus::EXPIRED,
            'deny', 'failure' => GatewayStatus::FAILED,
            'cancel' => GatewayStatus::CANCELLED,
            'refund', 'partial_refund' => GatewayStatus::REFUNDED,
            default => GatewayStatus::UNKNOWN,
        };
        $gross = array_key_exists('gross', $state) ? MidtransPaymentGateway::wholeRupiah($state['gross']) : (int) $state['amount'];

        return new ProviderStatus(
            $orderId,
            $status,
            isset($state['reference']) ? (string) $state['reference'] : null,
            $gross,
            $status === GatewayStatus::PAID ? CarbonImmutable::now() : null,
            ['transaction_status' => $state['status'], 'gross_amount' => $gross, 'transaction_id' => $state['reference'] ?? null],
        );
    }

    /** @return array<string, mixed>|null */
    private function state(string $orderId): ?array
    {
        $state = $this->cache->get(self::PREFIX.$orderId);

        return is_array($state) ? $state : null;
    }

    /** @param  array<string, mixed>  $state */
    private function put(string $orderId, array $state): void
    {
        $this->cache->put(self::PREFIX.$orderId, $state, 86400);
    }
}
