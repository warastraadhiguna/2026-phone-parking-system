<?php

namespace App\Domain\ParkingTransaction\Data;

use App\Domain\ParkingTransaction\Models\ParkingTransaction;
use App\Domain\Payment\Models\Payment;

final class QrisOutcome
{
    public function __construct(
        public readonly ParkingTransaction $transaction,
        public readonly Payment $payment,
        public readonly bool $created,
    ) {}
}
