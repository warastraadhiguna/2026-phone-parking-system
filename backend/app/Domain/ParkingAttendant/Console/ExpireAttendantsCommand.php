<?php

namespace App\Domain\ParkingAttendant\Console;

use App\Domain\ParkingAttendant\Actions\ExpireAttendants;
use Illuminate\Console\Command;

/** Scheduled daily (routes/console.php). */
final class ExpireAttendantsCommand extends Command
{
    protected $signature = 'attendants:expire';

    protected $description = 'Mark attendants whose registration validity has ended as EXPIRED';

    public function handle(ExpireAttendants $expire): int
    {
        $count = $expire->handle();
        $this->info("{$count} attendant(s) expired.");

        return self::SUCCESS;
    }
}
