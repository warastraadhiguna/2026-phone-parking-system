<?php

namespace App\Domain\ParkingTransaction\Internal;

use App\Support\Time\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Human-readable, unique, server-assigned numbers: TRX-YYMMDD-00000123 (date in WIB, global
 * sequence). Dev/default format; the official format is an owner item (master doc §62 #8).
 * Gaps are possible (rolled-back transactions); uniqueness is what matters.
 */
final class TransactionNumberGenerator
{
    public function next(CarbonImmutable $at): string
    {
        $sequence = (int) DB::scalar("SELECT nextval('parking_transaction_number_seq')");

        return sprintf('TRX-%s-%08d', $at->setTimezone(BusinessTime::timezone())->format('ymd'), $sequence);
    }
}
