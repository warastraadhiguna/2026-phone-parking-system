<?php

namespace App\Domain\Payment\Actions;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Models\User;
use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Enums\ProviderEventSource;
use App\Domain\Payment\Exceptions\GatewayUnavailable;
use App\Domain\Payment\Internal\PaymentStateMachine;
use App\Domain\Payment\Models\Payment;
use App\Support\Errors\ApiException;
use App\Support\Errors\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * The attendant withdraws an unpaid QR (e.g. the customer pays cash instead). The provider is
 * asked to cancel, and the payment takes whatever status the provider confirms. If the customer
 * paid in the meantime, the payment becomes PAID, never CANCELLED.
 */
final class CancelPayment
{
    public function __construct(
        private readonly PaymentGatewayInterface $gateway,
        private readonly PaymentStateMachine $machine,
        private readonly ChargePayment $charge,
        private readonly RecordAuditEvent $audit,
    ) {}

    /** @throws GatewayUnavailable */
    public function handle(Payment $payment, User $actor): Payment
    {
        $payment->refresh();
        if (! $payment->status->isOpen()) {
            throw new ApiException(ErrorCode::CONFLICT, 'Pembayaran ini sudah selesai dan tidak dapat dibatalkan.', ['status' => $payment->status->value]);
        }

        DB::transaction(fn () => $this->audit->handle(AuditAction::PAYMENT_CANCEL_REQUESTED, $actor, 'payment', $payment->payment_uuid, [
            'status' => $payment->status->value,
        ]));

        // A charge whose outcome is still unknown is resolved (and cancelled if pending) by ChargePayment.
        if ($payment->status === PaymentStatus::CREATED) {
            $resolved = $this->charge->handle($payment);
            if (! $resolved->status->isOpen()) {
                return $resolved;
            }
        }

        $status = $this->gateway->cancel($payment->provider_order_id);

        return DB::transaction(function () use ($payment, $status, $actor) {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $this->machine->apply($locked, $status, ProviderEventSource::CANCEL, $actor);

            return $locked;
        });
    }
}
