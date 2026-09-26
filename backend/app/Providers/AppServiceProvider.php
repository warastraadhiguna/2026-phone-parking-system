<?php

namespace App\Providers;

use App\Domain\Device\Services\DeviceGatekeeper;
use App\Domain\Identity\Contracts\MobileDeviceGate;
use App\Domain\ParkingTransaction\Services\TransactionPaymentOutcomes;
use App\Domain\Payment\Contracts\PayableTransactions;
use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Services\PaymentGatewayResolver;
use App\Domain\SystemConfiguration\Services\Settings;
use App\Support\Security\ProductionGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Identity asks the Device module whether a device may be used (ADR-0005).
        $this->app->bind(MobileDeviceGate::class, DeviceGatekeeper::class);

        // Memoises settings for one request/job only; the database stays the source of truth.
        $this->app->scoped(Settings::class);

        // The active payment provider (ADR-0006). The resolver refuses unsafe configurations,
        // e.g. the fake gateway in production.
        $this->app->scoped(PaymentGatewayInterface::class, fn ($app) => (new PaymentGatewayResolver($app))->make());

        // Payment outcomes are applied to parking transactions by their owner module.
        $this->app->bind(PayableTransactions::class, TransactionPaymentOutcomes::class);
    }

    public function boot(): void
    {
        // Fail fast: never boot with a payment configuration that is unsafe for this environment.
        /** @var array<string, mixed> $payment */
        $payment = config('payment');
        PaymentGatewayResolver::assertSafeConfiguration((string) $this->app->environment(), $payment);
        ProductionGuard::assertSafe((string) $this->app->environment(), (bool) config('app.debug'), (string) config('app.url'), (string) config('database.default'));

        // Password policy for every account (staff and attendants).
        Password::defaults(fn () => Password::min(10)->letters()->numbers()->max(128));

        // Read at request time by the global TrustProxies middleware (client IP for audit logs).
        $proxies = config('app.trusted_proxies');
        if (is_array($proxies) && $proxies !== []) {
            TrustProxies::at($proxies);
        }

        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('mobile-login', fn (Request $request) => [
            Limit::perMinute((int) config('identity.throttle.login_per_username'))
                ->by('mobile-login:'.mb_strtolower((string) $request->input('username')).'|'.$request->ip()),
            Limit::perMinute((int) config('identity.throttle.login_per_ip'))
                ->by('mobile-login-ip:'.$request->ip()),
        ]);

        RateLimiter::for('mobile-refresh', fn (Request $request) => Limit::perMinute((int) config('identity.throttle.refresh_per_ip'))
            ->by('mobile-refresh:'.$request->ip()));

        RateLimiter::for('payment-webhook', fn (Request $request) => Limit::perMinute((int) config('payment.webhook_per_minute', 300))
            ->by('payment-webhook:'.$request->ip()));
    }
}
