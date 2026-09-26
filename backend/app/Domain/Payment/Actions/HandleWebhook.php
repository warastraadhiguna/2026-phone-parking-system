<?php

namespace App\Domain\Payment\Actions;

use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Enums\ProviderEventOutcome;
use App\Domain\Payment\Enums\ProviderEventSource;
use App\Domain\Payment\Exceptions\InvalidWebhook;
use App\Domain\Payment\Internal\PaymentStateMachine;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentProviderEvent;
use Illuminate\Support\Facades\DB;

/**
 * Processes a provider notification (master doc §18; ADR-0006 webhook rules):
 * 1. verify authenticity first (the adapter checks the signature); nothing unverified is stored;
 * 2. lock the payment and ignore a notification that was already processed (event_key);
 * 3. check the amount and apply a forward-only status change, then record the event,
 * all in one DB transaction. A failure rolls everything back and the provider retries.
 */
final class HandleWebhook
{
    public function __construct(
        private readonly PaymentGatewayInterface $gateway,
        private readonly PaymentStateMachine $machine,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return ProviderEventOutcome|null null when the notification was a duplicate
     *
     * @throws InvalidWebhook
     */
    public function handle(array $payload): ?ProviderEventOutcome
    {
        $status = $this->gateway->verifyAndParseWebhook($payload);

        return DB::transaction(function () use ($status): ?ProviderEventOutcome {
            $payment = Payment::query()
                ->where('provider', $this->gateway->provider()->value)
                ->where('provider_order_id', $status->orderId)
                ->lockForUpdate()
                ->first();

            if ($payment === null) {
                $this->machine->recordUnknown($this->gateway->provider()->value, $status);

                return ProviderEventOutcome::UNKNOWN_PAYMENT;
            }

            if ($status->eventKey !== null && PaymentProviderEvent::query()->where('event_key', $status->eventKey)->exists()) {
                return null;
            }

            return $this->machine->apply($payment, $status, ProviderEventSource::WEBHOOK);
        });
    }
}
