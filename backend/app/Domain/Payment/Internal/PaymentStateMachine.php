<?php

namespace App\Domain\Payment\Internal;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Models\User;
use App\Domain\Payment\Contracts\PayableTransactions;
use App\Domain\Payment\Data\ChargeResult;
use App\Domain\Payment\Data\ProviderStatus;
use App\Domain\Payment\Enums\GatewayStatus;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Enums\ProviderEventOutcome;
use App\Domain\Payment\Enums\ProviderEventSource;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentProviderEvent;
use App\Support\RequestId\RequestId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * The only code that changes a payment's status (ADR-0006). Callers must hold the payment row
 * lock inside a DB transaction; every provider answer is recorded in payment_provider_events.
 *
 * - PAID only when the provider-reported amount equals the payment amount.
 * - PAID never moves backwards; unpaid final states never flip between each other.
 * - A verified PAID for an EXPIRED/FAILED/CANCELLED payment is a late confirmation: the payment
 *   becomes PAID (the money arrived) and the transaction is flagged for review.
 */
final class PaymentStateMachine
{
    public function __construct(
        private readonly PayableTransactions $payables,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function applyCharge(Payment $payment, ChargeResult $result): ProviderEventOutcome
    {
        $this->assertLocked();
        $before = $payment->status;

        if ($before !== PaymentStatus::CREATED) {
            return $this->record($payment, ProviderEventSource::CHARGE, $result->accepted ? 'PENDING' : 'REJECTED', null, ProviderEventOutcome::NO_CHANGE, $before, $result->raw);
        }

        if ($result->accepted) {
            $payment->forceFill([
                'status' => PaymentStatus::PENDING,
                'provider_reference' => $result->providerReference,
                'qr_string' => $result->qrString,
                'qr_image_url' => $result->qrImageUrl,
                'expired_at' => $result->expiresAt,
            ])->save();

            return $this->record($payment, ProviderEventSource::CHARGE, 'PENDING', null, ProviderEventOutcome::APPLIED, $before, $this->withoutQr($result->raw));
        }

        $payment->forceFill(['status' => PaymentStatus::FAILED, 'status_reason' => mb_substr((string) $result->rejectReason, 0, 255)])->save();
        $this->payables->paymentUnpaid($payment);
        $this->audit->handle(AuditAction::PAYMENT_FAILED, null, 'payment', $payment->payment_uuid, [
            'reason' => $result->rejectReason,
            'transaction_id' => $payment->transaction_id,
        ]);

        return $this->record($payment, ProviderEventSource::CHARGE, 'REJECTED', null, ProviderEventOutcome::REJECTED, $before, $result->raw);
    }

    public function apply(Payment $payment, ProviderStatus $status, ProviderEventSource $source, ?User $actor = null): ProviderEventOutcome
    {
        $this->assertLocked();
        $before = $payment->status;
        $outcome = ProviderEventOutcome::NO_CHANGE;

        if ($source === ProviderEventSource::STATUS_CHECK) {
            $payment->forceFill(['last_status_check_at' => now()]);
        }

        if ($status->status === GatewayStatus::PAID) {
            if ($status->grossAmount !== $payment->amount) {
                $outcome = ProviderEventOutcome::AMOUNT_MISMATCH;
                Log::warning('Payment amount mismatch: not marked PAID', [
                    'payment_uuid' => $payment->payment_uuid, 'expected' => $payment->amount, 'reported' => $status->grossAmount,
                ]);
                $this->payables->paymentAmountMismatch($payment, $status->grossAmount);
                $this->audit->handle(AuditAction::PAYMENT_AMOUNT_MISMATCH, $actor, 'payment', $payment->payment_uuid, [
                    'expected_amount' => $payment->amount,
                    'reported_amount' => $status->grossAmount,
                    'source' => $source->value,
                ]);
            } elseif ($before->canTransitionTo(PaymentStatus::PAID)) {
                $late = $before->isUnpaidFinal();
                $payment->forceFill([
                    'status' => PaymentStatus::PAID,
                    'paid_at' => $status->paidAt ?? now(),
                    'provider_reference' => $payment->provider_reference ?? $status->providerReference,
                ])->save();
                $this->payables->paymentPaid($payment, $late);
                $this->audit->handle($late ? AuditAction::PAYMENT_LATE_PAID : AuditAction::PAYMENT_PAID, $actor, 'payment', $payment->payment_uuid, [
                    'amount' => $payment->amount,
                    'previous_status' => $before->value,
                    'source' => $source->value,
                    'transaction_id' => $payment->transaction_id,
                ]);
                $outcome = ProviderEventOutcome::APPLIED;
            }
        } elseif ($status->status === GatewayStatus::REFUNDED && $before === PaymentStatus::PAID) {
            // A refund made at the provider must be booked as a payment_adjustment by finance.
            Log::warning('Provider reports a refund for a PAID payment', ['payment_uuid' => $payment->payment_uuid]);
        } else {
            $target = $status->status->paymentStatus();
            if ($target !== null && $target !== PaymentStatus::PENDING && $before->isOpen() && $before->canTransitionTo($target)) {
                $payment->forceFill(['status' => $target, 'status_reason' => 'Provider: '.$status->status->value])->save();
                $this->payables->paymentUnpaid($payment);
                $this->audit->handle(match ($target) {
                    PaymentStatus::EXPIRED => AuditAction::PAYMENT_EXPIRED,
                    PaymentStatus::CANCELLED => AuditAction::PAYMENT_CANCELLED,
                    default => AuditAction::PAYMENT_FAILED,
                }, $actor, 'payment', $payment->payment_uuid, [
                    'previous_status' => $before->value,
                    'source' => $source->value,
                    'transaction_id' => $payment->transaction_id,
                ]);
                $outcome = ProviderEventOutcome::APPLIED;
            }
        }

        if ($payment->isDirty()) {
            $payment->save();
        }

        return $this->record($payment, $source, $status->status->value, $status->grossAmount, $outcome, $before, $status->raw, $status->eventKey);
    }

    /**
     * A verified notification for an order this system does not know (e.g. another system on the
     * same merchant account). Recorded, never processed.
     */
    public function recordUnknown(string $provider, ProviderStatus $status): void
    {
        PaymentProviderEvent::query()->insertOrIgnore([
            'payment_id' => null,
            'provider' => $provider,
            'source' => ProviderEventSource::WEBHOOK->value,
            'event_key' => $status->eventKey,
            'provider_order_id' => mb_substr($status->orderId, 0, 64),
            'provider_status' => $status->status->value,
            'reported_amount' => $status->grossAmount,
            'outcome' => ProviderEventOutcome::UNKNOWN_PAYMENT->value,
            'payload' => json_encode((object) $status->raw, JSON_THROW_ON_ERROR),
            'request_id' => RequestId::current(),
            'created_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $payload */
    private function record(Payment $payment, ProviderEventSource $source, string $providerStatus, ?int $amount, ProviderEventOutcome $outcome, PaymentStatus $before, array $payload, ?string $eventKey = null): ProviderEventOutcome
    {
        PaymentProviderEvent::create([
            'payment_id' => $payment->id,
            'provider' => $payment->provider->value,
            'source' => $source,
            'event_key' => $eventKey,
            'provider_order_id' => $payment->provider_order_id,
            'provider_status' => $providerStatus,
            'reported_amount' => $amount,
            'outcome' => $outcome,
            'payment_status_before' => $before->value,
            'payment_status_after' => $payment->status->value,
            'payload' => (object) $payload,
            'request_id' => RequestId::current(),
        ]);

        return $outcome;
    }

    /**
     * The QR payload is kept on the payment row only.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function withoutQr(array $raw): array
    {
        unset($raw['qr_string']);

        return $raw;
    }

    private function assertLocked(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Payment state changes must run inside a DB transaction with the payment row locked.');
        }
    }
}
