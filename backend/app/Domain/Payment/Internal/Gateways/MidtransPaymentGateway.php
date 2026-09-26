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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Midtrans Core API, QRIS (docs checked 2026-09-25, docs.midtrans.com):
 * - POST {base}/v2/charge                 payment_type "qris", custom_expiry
 * - GET  {base}/v2/{order_id}/status
 * - POST {base}/v2/{order_id}/cancel      412 when the charge can no longer be changed
 * - Auth: Basic base64("server_key:")
 * - Notification signature: SHA512(order_id + status_code + gross_amount + server_key)
 *
 * Midtrans reports the outcome in the body's "status_code"; times are in WIB (GMT+7).
 * The server key never leaves this class and is never logged.
 */
final class MidtransPaymentGateway implements PaymentGatewayInterface
{
    private const PROVIDER_TIMEZONE = 'Asia/Jakarta';

    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $serverKey,
        private readonly string $acquirer,
        private readonly int $timeoutSeconds,
    ) {}

    public function provider(): PaymentProvider
    {
        return PaymentProvider::MIDTRANS;
    }

    public function createQrisCharge(QrisChargeRequest $request): ChargeResult
    {
        $body = $this->send('post', '/v2/charge', [
            'payment_type' => 'qris',
            'transaction_details' => ['order_id' => $request->orderId, 'gross_amount' => $request->amount],
            'item_details' => [[
                'id' => $request->itemId,
                'price' => $request->amount,
                'quantity' => 1,
                'name' => mb_substr($request->itemName, 0, 50),
            ]],
            'qris' => ['acquirer' => $this->acquirer],
            'custom_expiry' => [
                'order_time' => $request->orderTime->setTimezone(self::PROVIDER_TIMEZONE)->format('Y-m-d H:i:s O'),
                'expiry_duration' => $request->expiryMinutes,
                'unit' => 'minute',
            ],
        ]);

        $code = (string) ($body['status_code'] ?? '');
        if ($code === '201' && ($body['transaction_status'] ?? null) === 'pending' && is_string($body['transaction_id'] ?? null)) {
            $expiresAt = $this->time($body['expiry_time'] ?? null)
                ?? $request->orderTime->addMinutes($request->expiryMinutes);

            return ChargeResult::accepted(
                $body['transaction_id'],
                is_string($body['qr_string'] ?? null) ? $body['qr_string'] : null,
                $this->qrImageUrl($body),
                $expiresAt,
                $body,
            );
        }

        return ChargeResult::rejected(trim($code.' '.(string) ($body['status_message'] ?? 'Charge refused')), $body);
    }

    public function getStatus(string $orderId): ProviderStatus
    {
        return $this->status($orderId, $this->send('get', '/v2/'.rawurlencode($orderId).'/status'));
    }

    public function cancel(string $orderId): ProviderStatus
    {
        $body = $this->send('post', '/v2/'.rawurlencode($orderId).'/cancel');

        // 412: "Merchant cannot modify the status of the transaction" (already paid/expired).
        if ((string) ($body['status_code'] ?? '') === '412') {
            return $this->getStatus($orderId);
        }

        return $this->status($orderId, $body);
    }

    public function verifyAndParseWebhook(array $payload): ProviderStatus
    {
        foreach (['order_id', 'status_code', 'gross_amount', 'signature_key', 'transaction_status'] as $field) {
            if (! is_string($payload[$field] ?? null) || $payload[$field] === '') {
                throw new InvalidWebhook("Missing field {$field}.");
            }
        }
        if ($this->serverKey === null || $this->serverKey === '') {
            throw new InvalidWebhook('Midtrans server key is not configured.');
        }

        $expected = hash('sha512', $payload['order_id'].$payload['status_code'].$payload['gross_amount'].$this->serverKey);
        if (! hash_equals($expected, strtolower((string) $payload['signature_key']))) {
            throw new InvalidWebhook('Signature mismatch.');
        }

        $status = $this->status((string) $payload['order_id'], $payload);

        return new ProviderStatus(
            $status->orderId,
            $status->status,
            $status->providerReference,
            $status->grossAmount,
            $status->paidAt,
            $status->raw,
            hash('sha256', implode('|', [
                'MIDTRANS', $payload['order_id'], $payload['transaction_id'] ?? '', $payload['transaction_status'],
                $payload['status_code'], $payload['fraud_status'] ?? '', $payload['settlement_time'] ?? '',
            ])),
        );
    }

    /** @param  array<string, mixed>  $body */
    private function status(string $orderId, array $body): ProviderStatus
    {
        if ((string) ($body['status_code'] ?? '') === '404') {
            return new ProviderStatus($orderId, GatewayStatus::NOT_FOUND, raw: $this->sanitise($body));
        }

        $status = $this->map($body);

        return new ProviderStatus(
            is_string($body['order_id'] ?? null) ? $body['order_id'] : $orderId,
            $status,
            is_string($body['transaction_id'] ?? null) ? $body['transaction_id'] : null,
            self::wholeRupiah($body['gross_amount'] ?? null),
            $status === GatewayStatus::PAID ? ($this->time($body['settlement_time'] ?? null) ?? $this->time($body['transaction_time'] ?? null)) : null,
            $this->sanitise($body),
        );
    }

    /** @param  array<string, mixed>  $body */
    private function map(array $body): GatewayStatus
    {
        $fraud = $body['fraud_status'] ?? null;

        return match ($body['transaction_status'] ?? null) {
            'settlement' => $fraud === 'deny' ? GatewayStatus::FAILED : GatewayStatus::PAID,
            // Card-style capture; a "challenge" is not money received yet.
            'capture' => $fraud === null || $fraud === 'accept' ? GatewayStatus::PAID : GatewayStatus::PENDING,
            'pending' => GatewayStatus::PENDING,
            'expire' => GatewayStatus::EXPIRED,
            'deny', 'failure' => GatewayStatus::FAILED,
            'cancel' => GatewayStatus::CANCELLED,
            'refund', 'partial_refund', 'chargeback', 'partial_chargeback' => GatewayStatus::REFUNDED,
            default => GatewayStatus::UNKNOWN,
        };
    }

    /** "2000.00" → 2000. Anything that is not a whole rupiah amount → null (never guessed). */
    public static function wholeRupiah(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^(\d{1,15})(\.0+)?$/', $value, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $payload = []): array
    {
        if ($this->serverKey === null || $this->serverKey === '') {
            throw new GatewayUnavailable('Midtrans server key is not configured.');
        }

        try {
            $request = $this->client();
            /** @var Response $response */
            $response = $method === 'get' ? $request->get($path) : $request->post($path, $payload === [] ? null : $payload);
        } catch (ConnectionException $e) {
            throw new GatewayUnavailable('Midtrans unreachable: '.$e->getMessage(), previous: $e);
        }

        $body = $response->json();
        if ($response->serverError() || ! is_array($body)) {
            throw new GatewayUnavailable("Midtrans answered HTTP {$response->status()}.");
        }
        if (str_starts_with((string) ($body['status_code'] ?? ''), '5')) {
            throw new GatewayUnavailable('Midtrans reported status_code '.$body['status_code'].'.');
        }

        /** @var array<string, mixed> $body */
        return $body;
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->withBasicAuth((string) $this->serverKey, '')
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeoutSeconds)
            ->connectTimeout(min(5, $this->timeoutSeconds));
    }

    /** @param  array<string, mixed>  $body */
    private function qrImageUrl(array $body): ?string
    {
        foreach ((array) ($body['actions'] ?? []) as $action) {
            if (is_array($action) && ($action['name'] ?? null) === 'generate-qr-code' && is_string($action['url'] ?? null)) {
                return $action['url'];
            }
        }

        return null;
    }

    private function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, self::PROVIDER_TIMEZONE);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function sanitise(array $body): array
    {
        unset($body['signature_key']);

        return $body;
    }
}
