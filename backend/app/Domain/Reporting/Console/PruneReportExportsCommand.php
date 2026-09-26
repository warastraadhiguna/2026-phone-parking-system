<?php

namespace App\Domain\Reporting\Console;

use App\Domain\Reporting\Enums\ExportStatus;
use App\Domain\Reporting\Jobs\GenerateReportExport;
use App\Domain\Reporting\Models\ReportExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/** Deletes expired export files (copies of data that stays in the source tables); keeps the record. */
final class PruneReportExportsCommand extends Command
{
    protected $signature = 'reports:prune-exports';

    protected $description = 'Delete report export files past their retention period';

    public function handle(): int
    {
        $n = 0;
        ReportExport::query()->where('status', ExportStatus::DONE->value)->where('expires_at', '<', now())->each(function (ReportExport $e) use (&$n) {
            if ($e->file_path !== null) {
                Storage::disk(GenerateReportExport::DISK)->delete($e->file_path);
            }
            $e->forceFill(['status' => ExportStatus::EXPIRED, 'file_path' => null])->save();
            $n++;
        });
        $this->info("{$n} export file(s) removed.");

        return self::SUCCESS;
    }
}
