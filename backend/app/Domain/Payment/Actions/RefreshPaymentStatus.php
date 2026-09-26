<?php

namespace App\Domain\Payment\Actions;

use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Enums\ProviderEventSource;
use App\Domain\Payment\Exceptions\GatewayUnavailable;
use App\Domain\Payment\Internal\PaymentStateMachine;
use App\Domain\Payment\Models\Payment;
use App\Domain\SystemConfiguration\Enums\SettingKey;
use App\Domain\SystemConfiguration\Services\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Asks the provider for the current status of an open payment and applies it. The call is
 * backend-to-backend, so the answer is authentic. Used by the app's polling endpoint (throttled
 * by the qris_status_check_seconds setting) and by the pending-payments job. Webhooks remain the
 * primary path; this covers lost notifications.
 */
final class RefreshPaymentStatus
{
    public function __construct(
        private readonly PaymentGatewayInterface $gateway,
        private readonly PaymentStateMachine $machine,
        private readonly ChargePayment $charge,
        private readonly Settings $settings,
    ) {}

    /** @throws GatewayUnavailable */
    public function handle(Payment $payment, bool $force = false): Payment
    {
        $payment->refresh();
        if (! $payment->status->isOpen() || $payment->provider !== $this->gateway->provider()) {
            return $payment;
        }
        if (! $force && $payment->last_status_check_at !== null
            && $payment->last_status_check_at->diffInSeconds(now(), true) < $this->settings->int(SettingKey::QRIS_STATUS_CHECK_SECONDS)) {
            return $payment;
        }

        if ($payment->status === PaymentStatus::CREATED) {
            return $this->charge->handle($payment);
        }

        $status = $this->gateway->getStatus($payment->provider_order_id);

        return DB::transaction(function () use ($payment, $status) {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $this->machine->apply($locked, $status, ProviderEventSource::STATUS_CHECK);

            return $locked;
        });
    }
}
