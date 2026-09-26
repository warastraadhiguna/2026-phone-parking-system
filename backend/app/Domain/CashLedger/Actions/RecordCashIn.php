<?php

namespace App\Domain\CashLedger\Actions;

use App\Domain\CashLedger\Enums\LedgerEntryType;
use App\Domain\CashLedger\Internal\LedgerWriter;
use App\Domain\CashLedger\Models\CashLedgerEntry;
use App\Domain\ParkingTransaction\Models\ParkingTransaction;

/** Master doc §11 / §46 rule 9: every cash transaction adds its amount to the attendant's cash. */
final class RecordCashIn
{
    public function __construct(private readonly LedgerWriter $writer) {}

    /** Call inside the transaction that creates the parking transaction. */
    public function handle(ParkingTransaction $transaction): CashLedgerEntry
    {
        return $this->writer->append($transaction->attendant_id, LedgerEntryType::PARKING_CASH_IN, $transaction->charged_tariff_amount, [
            'shift_id' => $transaction->shift_id,
            'transaction_id' => $transaction->id,
            'description' => $transaction->transaction_number,
        ]);
    }
}
