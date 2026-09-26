<?php

namespace App\Domain\Payment\Actions;

use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Data\QrisChargeRequest;
use App\Domain\Payment\Enums\GatewayStatus;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Enums\ProviderEventSource;
use App\Domain\Payment\Exceptions\GatewayUnavailable;
use App\Domain\Payment\Internal\PaymentStateMachine;
use App\Domain\Payment\Models\Payment;
use App\Domain\SystemConfiguration\Enums\SettingKey;
use App\Domain\SystemConfiguration\Services\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Asks the provider for a dynamic QR for a CREATED payment (master doc §16, §19).
 *
 * The provider call happens outside any DB transaction. If its outcome is unknown (timeout),
 * the payment stays CREATED and GatewayUnavailable is thrown; the next attempt first asks the
 * provider whether the charge exists:
 *   not found → charge again (same order id);
 *   pending   → the QR was never stored here, so the charge is cancelled at the provider;
 *   final     → that status is applied.
 */
final class ChargePayment
{
    public function __construct(
        private readonly PaymentGatewayInterface $gateway,
        private readonly PaymentStateMachine $machine,
        private readonly Settings $settings,
    ) {}

    /** @throws GatewayUnavailable */
    public function handle(Payment $payment): Payment
    {
        $firstAttempt = DB::transaction(function () use ($payment): ?bool {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($locked->status !== PaymentStatus::CREATED) {
                return null;
            }
            $first = $locked->charge_attempts === 0;
            if ($first) {
                $locked->forceFill(['charge_attempts' => 1])->save();
            }

            return $first;
        });

        if ($firstAttempt === null) {
            return $payment->refresh();
        }
        if (! $firstAttempt) {
            return $this->recover($payment);
        }

        return $this->charge($payment);
    }

    private function charge(Payment $payment): Payment
    {
        $payment->loadMissing('transaction');
        $transaction = $payment->transaction;
        $request = new QrisChargeRequest(
            $payment->provider_order_id,
            $payment->amount,
            $transaction->vehicle_type->value,
            'Parkir '.$transaction->vehicle_type->label().' '.$transaction->transaction_number,
            CarbonImmutable::now(),
            $this->settings->int(SettingKey::QRIS_EXPIRY_MINUTES),
        );

        $result = $this->gateway->createQrisCharge($request);

        return DB::transaction(function () use ($payment, $result) {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $this->machine->applyCharge($locked, $result);

            return $locked;
        });
    }

    private function recover(Payment $payment): Payment
    {
        $status = $this->gateway->getStatus($payment->provider_order_id);

        if ($status->status === GatewayStatus::NOT_FOUND) {
            DB::transaction(fn () => Payment::query()->lockForUpdate()->findOrFail($payment->id)->increment('charge_attempts'));

            return $this->charge($payment);
        }

        if ($status->status === GatewayStatus::PENDING) {
            $status = $this->gateway->cancel($payment->provider_order_id);
        }

        return DB::transaction(function () use ($payment, $status) {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $this->machine->apply($locked, $status, ProviderEventSource::STATUS_CHECK);

            return $locked;
        });
    }
}
