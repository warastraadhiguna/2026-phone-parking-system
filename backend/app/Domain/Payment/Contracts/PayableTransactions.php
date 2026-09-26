<?php

namespace App\Domain\Payment\Contracts;

use App\Domain\Payment\Models\Payment;

/**
 * How the Payment module tells the owner of the paid-for record what happened, without writing
 * its tables (module rule 1). Implemented by the ParkingTransaction module. Called inside the
 * same DB transaction as the payment change, with the payment row locked.
 */
interface PayableTransactions
{
    /** The payment became PAID. $late: it had already been EXPIRED/FAILED/CANCELLED. */
    public function paymentPaid(Payment $payment, bool $late): void;

    /** The payment ended without money (EXPIRED, FAILED or CANCELLED). */
    public function paymentUnpaid(Payment $payment): void;

    /** The provider reported a different amount; nothing was marked PAID. */
    public function paymentAmountMismatch(Payment $payment, ?int $reportedAmount): void;
}
