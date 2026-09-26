<?php

namespace App\Domain\CashLedger\Enums;

/** Master doc §12. Amounts are signed rupiah: cash the attendant holds goes up (+) or down (−). */
enum LedgerEntryType: string
{
    /** Cash received for a parking transaction (+). */
    case PARKING_CASH_IN = 'PARKING_CASH_IN';

    /** Cash handed over in a verified settlement (−). Phase 7. */
    case SETTLEMENT_OUT = 'SETTLEMENT_OUT';

    /** Approved correction (±). */
    case ADJUSTMENT = 'ADJUSTMENT';

    /** Exact counter-entry of an earlier entry (e.g. a voided cash transaction). */
    case REVERSAL = 'REVERSAL';

    public function label(): string
    {
        return match ($this) {
            self::PARKING_CASH_IN => 'Penerimaan tunai parkir',
            self::SETTLEMENT_OUT => 'Setoran',
            self::ADJUSTMENT => 'Penyesuaian',
            self::REVERSAL => 'Pembatalan (reversal)',
        };
    }
}
