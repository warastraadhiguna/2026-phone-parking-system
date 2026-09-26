<?php

namespace App\Domain\Payment\Console;

use App\Domain\Payment\Actions\HandleWebhook;
use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Internal\Gateways\FakePaymentGateway;
use App\Domain\Payment\Models\Payment;
use Illuminate\Console\Command;

/**
 * Local development only: simulates the customer's action at the fake provider and delivers the
 * signed notification through the normal webhook processing. Refuses unless the fake gateway is
 * active (which is impossible in production).
 */
final class FakePaymentCommand extends Command
{
    protected $signature = 'payments:fake-simulate {payment_uuid} {status=settlement : settlement|expire|cancel|deny}';

    protected $description = '[dev] Simulate a provider event for a payment of the fake gateway';

    public function handle(PaymentGatewayInterface $gateway, HandleWebhook $webhook): int
    {
        if (! $gateway instanceof FakePaymentGateway) {
            $this->error('The fake gateway is not active.');

            return self::FAILURE;
        }

        $payment = Payment::query()->where('payment_uuid', strtolower((string) $this->argument('payment_uuid')))->first();
        if ($payment === null) {
            $this->error('Payment not found.');

            return self::FAILURE;
        }

        $outcome = $webhook->handle($gateway->simulate($payment->provider_order_id, (string) $this->argument('status')));
        $this->info('Outcome: '.($outcome->value ?? 'DUPLICATE').'; payment status: '.$payment->refresh()->status->value);

        return self::SUCCESS;
    }
}
