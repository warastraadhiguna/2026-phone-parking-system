<?php

namespace App\Domain\Payment\Actions;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Models\User;
use App\Domain\Payment\Enums\AdjustmentType;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentAdjustment;
use App\Support\Errors\RuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records money returned to a payer outside the provider (ADR-0006, Q3). The payment stays PAID
 * and the transaction keeps its status; reconciliation reports received − refunded.
 * Rules (also enforced by the database): PAID payments only, total refunds ≤ payment amount.
 */
final class RecordManualRefund
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(Payment $payment, User $actor, int $amount, string $reason, CarbonImmutable $refundedAt): PaymentAdjustment
    {
        return DB::transaction(function () use ($payment, $actor, $amount, $reason, $refundedAt) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($payment->status !== PaymentStatus::PAID) {
                throw new RuleViolation('amount', 'Pengembalian dana hanya untuk pembayaran yang sudah lunas.');
            }
            $remaining = $payment->amount - $payment->refundedAmount();
            if ($amount < 1 || $amount > $remaining) {
                throw new RuleViolation('amount', "Jumlah pengembalian harus antara 1 dan {$remaining}.");
            }
            if ($refundedAt->isFuture()) {
                throw new RuleViolation('refunded_at', 'Waktu pengembalian tidak boleh di masa depan.');
            }

            $adjustment = PaymentAdjustment::create([
                'adjustment_uuid' => (string) Str::uuid(),
                'payment_id' => $payment->id,
                'type' => AdjustmentType::MANUAL_REFUND,
                'amount' => $amount,
                'reason' => trim($reason),
                'refunded_at' => $refundedAt,
                'recorded_by' => $actor->id,
            ]);

            $this->audit->handle(AuditAction::MANUAL_REFUND_RECORDED, $actor, 'payment', $payment->payment_uuid, [
                'adjustment_uuid' => $adjustment->adjustment_uuid,
                'amount' => $amount,
                'reason' => $adjustment->reason,
                'refunded_total' => $payment->amount - $remaining + $amount,
            ]);

            return $adjustment;
        });
    }
}
