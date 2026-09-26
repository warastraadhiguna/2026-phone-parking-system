<?php

namespace App\Domain\CashLedger\Console;

use App\Domain\CashLedger\Services\CashBalances;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Proves the derived balances against the ledger (scheduled daily). With --fix, rebuilds the
 * mismatching rows from the ledger (ADR-0007). Exit code 1 when mismatches were found.
 */
final class VerifyCashBalancesCommand extends Command
{
    protected $signature = 'cash:verify-balances {--fix : Rebuild mismatching derived balances from the ledger}';

    protected $description = 'Verify attendant cash balances against the cash ledger';

    public function handle(CashBalances $balances): int
    {
        $mismatches = $this->option('fix') ? $balances->rebuild() : $balances->mismatches();

        if ($mismatches === []) {
            $this->info('All attendant cash balances match the ledger.');

            return self::SUCCESS;
        }

        Log::error('Cash balance mismatch', ['mismatches' => $mismatches, 'fixed' => (bool) $this->option('fix')]);
        foreach ($mismatches as $m) {
            $this->warn("attendant {$m['attendant_id']}: derived {$m['derived']} vs ledger {$m['ledger']}");
        }
        $this->line($this->option('fix') ? 'Derived balances rebuilt from the ledger.' : 'Run with --fix to rebuild from the ledger.');

        return self::FAILURE;
    }
}
