<?php

namespace App\Domain\ParkingTransaction\Services;

use App\Domain\ParkingTransaction\Enums\TransactionFlag;
use App\Domain\ParkingTransaction\Enums\TransactionStatus;
use App\Domain\ParkingTransaction\Models\ParkingTransaction;
use App\Domain\Payment\Contracts\PayableTransactions;
use App\Domain\Payment\Models\Payment;

/**
 * Applies payment outcomes to QRIS parking transactions (master doc §16, §19, §47). Called by the
 * Payment module inside its DB transaction; the transitions are also enforced by the DB trigger.
 *
 *   PAID:            WAITING_PAYMENT → PAID → COMPLETED
 *   late PAID:       transaction stays CANCELLED, flagged LATE_PAYMENT (finance decides: refund)
 *   EXPIRED/FAILED/CANCELLED: WAITING_PAYMENT → CANCELLED
 */
final class TransactionPaymentOutcomes implements PayableTransactions
{
    public function paymentPaid(Payment $payment, bool $late): void
    {
        $transaction = $this->lock($payment);

        if ($transaction->status === TransactionStatus::WAITING_PAYMENT) {
            $transaction->forceFill(['status' => TransactionStatus::PAID])->save();
            $transaction->forceFill(['status' => TransactionStatus::COMPLETED])->save();

            return;
        }

        $transaction->withFlags([TransactionFlag::LATE_PAYMENT])->save();
    }

    public function paymentUnpaid(Payment $payment): void
    {
        $transaction = $this->lock($payment);

        if ($transaction->status === TransactionStatus::WAITING_PAYMENT) {
            $transaction->forceFill(['status' => TransactionStatus::CANCELLED])->save();
        }
    }

    public function paymentAmountMismatch(Payment $payment, ?int $reportedAmount): void
    {
        $this->lock($payment)->withFlags([TransactionFlag::PAYMENT_AMOUNT_MISMATCH])->save();
    }

    private function lock(Payment $payment): ParkingTransaction
    {
        return ParkingTransaction::query()->lockForUpdate()->findOrFail($payment->transaction_id);
    }
}
