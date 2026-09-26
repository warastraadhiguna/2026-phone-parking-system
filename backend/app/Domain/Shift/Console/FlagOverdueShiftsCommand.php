<?php

namespace App\Domain\Shift\Console;

use App\Domain\Shift\Actions\FlagOverdueShifts;
use Illuminate\Console\Command;

/** Scheduled hourly (routes/console.php). */
final class FlagOverdueShiftsCommand extends Command
{
    protected $signature = 'shifts:flag-overdue';

    protected $description = 'Flag OPEN shifts that exceed max_open_shift_hours';

    public function handle(FlagOverdueShifts $flag): int
    {
        $this->info($flag->handle().' shift(s) flagged as overdue.');

        return self::SUCCESS;
    }
}
