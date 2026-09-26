<?php

namespace App\Domain\ParkingTransaction\Data;

use App\Domain\ParkingTransaction\Models\ParkingTransaction;

/** $created is false for an idempotent replay. */
final class TransactionOutcome
{
    public function __construct(
        public readonly ParkingTransaction $transaction,
        public readonly bool $created,
    ) {}
}
