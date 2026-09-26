<?php

namespace App\Domain\Reporting\Actions;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Models\User;
use App\Domain\Reporting\Data\ReportParams;
use App\Domain\Reporting\Enums\ExportFormat;
use App\Domain\Reporting\Enums\ExportStatus;
use App\Domain\Reporting\Enums\ReportType;
use App\Domain\Reporting\Jobs\GenerateReportExport;
use App\Domain\Reporting\Models\ReportExport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Queues an export. The file is built by GenerateReportExport; the request returns immediately. */
final class RequestReportExport
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(User $user, ReportType $type, ExportFormat $format, ReportParams $params): ReportExport
    {
        $export = DB::transaction(function () use ($user, $type, $format, $params) {
            $export = ReportExport::create([
                'export_uuid' => (string) Str::uuid(),
                'requested_by' => $user->id,
                'report_type' => $type,
                'format' => $format,
                'params' => $params->toArray(),
                'status' => ExportStatus::QUEUED,
            ]);
            $this->audit->handle(AuditAction::REPORT_EXPORT_REQUESTED, $user, 'report_export', $export->export_uuid, [
                'report' => $type->value,
                'format' => $format->value,
                'params' => $params->toArray(),
            ]);

            return $export;
        });

        GenerateReportExport::dispatch($export->id)->afterCommit();

        return $export->refresh();
    }
}
