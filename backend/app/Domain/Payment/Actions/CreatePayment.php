<?php

namespace App\Domain\Payment\Actions;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Models\User;
use App\Domain\ParkingTransaction\Models\ParkingTransaction;
use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Creates the payment record for a QRIS transaction (status CREATED). No provider call here:
 * it runs inside the caller's DB transaction; ChargePayment talks to the provider afterwards.
 */
final class CreatePayment
{
    public function __construct(
        private readonly PaymentGatewayInterface $gateway,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(ParkingTransaction $transaction, User $actor, ?string $deviceUuid = null): Payment
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('CreatePayment must run inside the transaction that creates the parking transaction.');
        }

        $uuid = (string) Str::uuid();
        $payment = Payment::create([
            'payment_uuid' => $uuid,
            'transaction_id' => $transaction->id,
            'provider' => $this->gateway->provider(),
            'provider_order_id' => $uuid,
            'payment_method' => 'QRIS',
            'amount' => $transaction->charged_tariff_amount,
            'status' => PaymentStatus::CREATED,
        ]);

        $this->audit->handle(AuditAction::PAYMENT_CREATED, $actor, 'payment', $uuid, [
            'transaction_uuid' => $transaction->transaction_uuid,
            'provider' => $payment->provider->value,
            'amount' => $payment->amount,
        ], deviceUuid: $deviceUuid);

        return $payment;
    }
}
