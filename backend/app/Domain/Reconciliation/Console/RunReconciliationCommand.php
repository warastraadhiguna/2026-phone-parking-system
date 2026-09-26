<?php

namespace App\Domain\Reconciliation\Console;

use App\Domain\Reconciliation\Actions\RunReconciliation;
use App\Support\Time\BusinessTime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Daily reconciliation (scheduled for the previous WIB day). Exit code 1 and an error log when
 * integrity errors were found, so monitoring can alert.
 */
final class RunReconciliationCommand extends Command
{
    protected $signature = 'reconciliation:run {date? : Business date (Y-m-d, WIB); default yesterday}';

    protected $description = 'Reconcile transactions, payments, the cash ledger and settlements for one day';

    public function handle(RunReconciliation $run): int
    {
        $date = (string) ($this->argument('date') ?? now(BusinessTime::timezone())->subDay()->toDateString());
        $result = $run->handle($date);

        $this->info("Reconciliation {$date}: revenue {$result->total_revenue}, outstanding cash {$result->cash_outstanding}, "
            ."QRIS difference {$result->qris_difference}, {$result->mismatch_count} mismatch(es), {$result->error_count} error(s).");

        if ($result->error_count > 0) {
            Log::error('Reconciliation found integrity errors', ['business_date' => $date, 'run_uuid' => $result->run_uuid, 'errors' => $result->error_count]);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
