<?php

namespace App\Domain\FraudReview\Console;

use App\Domain\FraudReview\Actions\CollectAnomalies;
use Illuminate\Console\Command;

final class CollectAnomaliesCommand extends Command
{
    protected $signature = 'anomalies:collect';

    protected $description = 'Add new review flags and reconciliation mismatches to the review queue';

    public function handle(CollectAnomalies $collect): int
    {
        $added = $collect->handle();
        $this->info("Review queue: +{$added['transactions']} transaction, +{$added['shifts']} shift, +{$added['reconciliation']} reconciliation item(s).");

        return self::SUCCESS;
    }
}
