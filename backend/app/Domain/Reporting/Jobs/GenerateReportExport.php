<?php

namespace App\Domain\Reporting\Jobs;

use App\Domain\Reporting\Data\ReportParams;
use App\Domain\Reporting\Enums\ExportStatus;
use App\Domain\Reporting\Internal\ExportWriter;
use App\Domain\Reporting\Models\ReportExport;
use App\Domain\Reporting\Services\ReportCatalog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Builds one export file on the queue (master doc §32, §39). Never runs in a web request. */
final class GenerateReportExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const DISK = 'local';

    public const RETENTION_DAYS = 7;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly int $exportId) {}

    public function handle(ReportCatalog $catalog, ExportWriter $writer): void
    {
        $export = ReportExport::query()->find($this->exportId);
        if ($export === null || $export->status !== ExportStatus::QUEUED) {
            return;
        }
        $export->forceFill(['status' => ExportStatus::RUNNING, 'started_at' => now()])->save();

        $relative = "exports/{$export->export_uuid}.{$export->format->value}";
        $disk = Storage::disk(self::DISK);
        $disk->makeDirectory('exports');
        $absolute = $disk->path($relative);

        try {
            $params = ReportParams::fromArray($export->params);
            $title = $export->report_type->label().($export->report_type->usesDateRange() ? " ({$params->from} s.d. {$params->to})" : '');
            $rows = $writer->write($export->format, $absolute, $title, $catalog->columns($export->report_type), $catalog->rows($export->report_type, $params));

            $export->forceFill([
                'status' => ExportStatus::DONE,
                'file_path' => $relative,
                'row_count' => $rows,
                'finished_at' => now(),
                'expires_at' => now()->addDays(self::RETENTION_DAYS),
            ])->save();
        } catch (Throwable $e) {
            @unlink($absolute);
            Log::warning('Report export failed', ['export_uuid' => $export->export_uuid, 'message' => $e->getMessage()]);
            $export->forceFill(['status' => ExportStatus::FAILED, 'error' => mb_substr($e->getMessage(), 0, 500), 'finished_at' => now()])->save();
        }
    }
}
