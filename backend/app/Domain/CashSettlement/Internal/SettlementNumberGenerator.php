<?php

namespace App\Domain\CashSettlement\Internal;

use App\Support\Time\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** STL-YYMMDD-00000123 (date in WIB, global sequence). Dev/default format, like transaction numbers. */
final class SettlementNumberGenerator
{
    public function next(CarbonImmutable $at): string
    {
        $sequence = (int) DB::scalar("SELECT nextval('cash_settlement_number_seq')");

        return sprintf('STL-%s-%08d', $at->setTimezone(BusinessTime::timezone())->format('ymd'), $sequence);
    }
}
