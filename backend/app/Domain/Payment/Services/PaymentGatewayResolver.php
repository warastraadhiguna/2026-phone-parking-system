<?php

namespace App\Domain\Payment\Services;

use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Exceptions\UnsafePaymentConfiguration;
use App\Domain\Payment\Internal\Gateways\FakePaymentGateway;
use App\Domain\Payment\Internal\Gateways\MidtransPaymentGateway;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Foundation\Application;

/**
 * Builds the active PaymentGatewayInterface from config/payment.php and enforces the guardrails
 * (ADR-0006, master doc §54):
 * - the Fake gateway can never be active in production;
 * - the Midtrans production environment needs APP_ENV=production AND an explicit approval flag;
 * - anything else is refused, never silently replaced by a default.
 */
final class PaymentGatewayResolver
{
    public function __construct(private readonly Application $app) {}

    public function make(): PaymentGatewayInterface
    {
        self::assertSafeConfiguration($this->app->environment(), $this->config());
        $config = $this->config();

        if ($config['gateway'] === 'fake') {
            $key = (string) ($config['fake']['signing_key'] ?? '');

            return new FakePaymentGateway(
                $this->app->make(CacheFactory::class)->store(),
                $key !== '' ? $key : hash_hmac('sha256', 'fake-payment-gateway', (string) config('app.key')),
            );
        }

        $midtrans = $config['midtrans'];
        $environment = (string) $midtrans['environment'];

        return new MidtransPaymentGateway(
            (string) $midtrans['base_urls'][$environment],
            is_string($midtrans['server_key'] ?? null) ? $midtrans['server_key'] : null,
            (string) $midtrans['qris_acquirer'],
            max(1, (int) $midtrans['timeout_seconds']),
        );
    }

    /**
     * Called at boot (fail fast) and whenever a gateway is built.
     *
     * @param  array<string, mixed>  $config  config('payment')
     *
     * @throws UnsafePaymentConfiguration
     */
    public static function assertSafeConfiguration(string $appEnvironment, array $config): void
    {
        $gateway = $config['gateway'] ?? null;
        $production = $appEnvironment === 'production';

        if (! in_array($gateway, ['fake', 'midtrans'], true)) {
            throw new UnsafePaymentConfiguration('PAYMENT_GATEWAY must be "midtrans" or "fake".');
        }
        if ($gateway === 'fake' && $production) {
            throw new UnsafePaymentConfiguration('The fake payment gateway must never be active in production.');
        }
        if ($gateway === 'midtrans') {
            $environment = $config['midtrans']['environment'] ?? null;
            if (! in_array($environment, ['sandbox', 'production'], true)) {
                throw new UnsafePaymentConfiguration('MIDTRANS_ENVIRONMENT must be "sandbox" or "production".');
            }
            if ($environment === 'production' && (! $production || ($config['midtrans']['production_approved'] ?? false) !== true)) {
                throw new UnsafePaymentConfiguration('Midtrans production requires APP_ENV=production and MIDTRANS_PRODUCTION_APPROVED=true.');
            }
        }
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        /** @var array<string, mixed> $config */
        $config = $this->app->make('config')->get('payment');

        return $config;
    }
}
