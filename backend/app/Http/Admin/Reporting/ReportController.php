<?php

namespace App\Http\Admin\Reporting;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Models\User;
use App\Domain\ParkingAttendant\Models\ParkingAttendant;
use App\Domain\ParkingLocation\Models\ParkingLocation;
use App\Domain\Reporting\Actions\RequestReportExport;
use App\Domain\Reporting\Data\ReportParams;
use App\Domain\Reporting\Enums\ExportFormat;
use App\Domain\Reporting\Enums\ExportStatus;
use App\Domain\Reporting\Enums\ReportType;
use App\Domain\Reporting\Jobs\GenerateReportExport;
use App\Domain\Reporting\Models\ReportExport;
use App\Domain\Reporting\Services\ReportCatalog;
use App\Http\Admin\Support\Options;
use App\Support\Time\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports and queued exports (master doc §32). reports.view previews; reports.export queues
 * files. A report never widens access: the audit-log report also needs audit.view.
 */
final class ReportController
{
    public function index(Request $request, ReportCatalog $catalog): Response
    {
        /** @var User $user */
        $user = $request->user();
        $types = array_values(array_filter(ReportType::cases(), fn (ReportType $t) => $this->allowed($user, $t)));
        $today = now(BusinessTime::timezone())->toDateString();

        $preview = null;
        $filters = ['type' => '', 'from' => now(BusinessTime::timezone())->startOfMonth()->toDateString(), 'to' => $today, 'location_id' => '', 'attendant_id' => '', 'payment_method' => ''];
        if ($request->filled('type')) {
            [$type, $params] = $this->validated($request, $user);
            $rows = [];
            foreach ($catalog->rows($type, $params) as $row) {
                $rows[] = $row;
                if (count($rows) >= (int) config('reporting.preview_rows')) {
                    break;
                }
            }
            $preview = ['type' => $type->value, 'title' => $type->label(), 'columns' => $catalog->columns($type), 'rows' => $rows, 'limit' => (int) config('reporting.preview_rows')];
            $filters = ['type' => $type->value, 'from' => $params->from, 'to' => $params->to, 'location_id' => (string) ($params->locationId ?? ''), 'attendant_id' => (string) ($params->attendantId ?? ''), 'payment_method' => $params->paymentMethod ?? ''];
        }

        return Inertia::render('Reports/Index', [
            'types' => array_map(fn (ReportType $t) => ['value' => $t->value, 'label' => $t->label(), 'dated' => $t->usesDateRange()], $types),
            'filters' => $filters,
            'preview' => $preview,
            'options' => [
                'locations' => ParkingLocation::query()->orderBy('location_code')->get(['id', 'location_code', 'name'])->map(fn ($l) => ['value' => (string) $l->id, 'label' => "{$l->location_code} — {$l->name}"]),
                'attendants' => ParkingAttendant::query()->orderBy('attendant_code')->get(['id', 'attendant_code', 'name'])->map(fn ($a) => ['value' => (string) $a->id, 'label' => "{$a->attendant_code} — {$a->name}"]),
            ],
            'exports' => ReportExport::query()->where('requested_by', $user->id)->orderByDesc('id')->limit(20)->get()->map(fn (ReportExport $e) => [
                'id' => $e->id,
                'report' => $e->report_type->label(),
                'format' => strtoupper($e->format->value),
                'params' => $e->params,
                'status' => Options::one($e->status),
                'row_count' => $e->row_count,
                'error' => $e->error,
                'created_at' => $e->created_at->toIso8601String(),
                'expires_at' => $e->expires_at?->toIso8601String(),
            ]),
            'can' => ['export' => $user->can('reports.export')],
        ]);
    }

    public function export(Request $request, RequestReportExport $requestExport): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        [$type, $params] = $this->validated($request, $user);
        $format = ExportFormat::from((string) $request->validate(['format' => ['required', Rule::enum(ExportFormat::class)]])['format']);

        $requestExport->handle($user, $type, $format, $params);

        return back()->with('success', 'Ekspor dimasukkan ke antrean. File dapat diunduh di daftar ekspor setelah selesai.');
    }

    public function download(Request $request, ReportExport $export, RecordAuditEvent $audit): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($export->requested_by === $user->id, 404);
        abort_unless($export->status === ExportStatus::DONE && $export->file_path !== null, 404);

        DB::transaction(fn () => $audit->handle(AuditAction::REPORT_EXPORT_DOWNLOADED, $user, 'report_export', $export->export_uuid, [
            'report' => $export->report_type->value,
            'rows' => $export->row_count,
        ]));

        $name = sprintf('%s_%s.%s', $export->report_type->value, $export->created_at->setTimezone(BusinessTime::timezone())->format('Ymd_His'), $export->format->value);

        return Storage::disk(GenerateReportExport::DISK)->download($export->file_path, $name, [
            'Content-Type' => $export->format->mimeType(),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @return array{0: ReportType, 1: ReportParams} */
    private function validated(Request $request, User $user): array
    {
        $data = $request->validate([
            'type' => ['required', Rule::enum(ReportType::class)],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'location_id' => ['nullable', 'integer'],
            'attendant_id' => ['nullable', 'integer'],
            'payment_method' => ['nullable', Rule::in(['CASH', 'QRIS'])],
        ], attributes: ['from' => 'tanggal awal', 'to' => 'tanggal akhir']);

        $type = ReportType::from((string) $data['type']);
        abort_unless($this->allowed($user, $type), 403);

        $days = CarbonImmutable::parse($data['from'])->diffInDays(CarbonImmutable::parse($data['to']), true) + 1;
        if ($days > (int) config('reporting.max_range_days')) {
            throw ValidationException::withMessages(['to' => 'Rentang tanggal maksimal '.config('reporting.max_range_days').' hari.']);
        }

        return [$type, new ReportParams(
            (string) $data['from'],
            (string) $data['to'],
            isset($data['location_id']) ? (int) $data['location_id'] : null,
            isset($data['attendant_id']) ? (int) $data['attendant_id'] : null,
            isset($data['payment_method']) ? (string) $data['payment_method'] : null,
        )];
    }

    private function allowed(User $user, ReportType $type): bool
    {
        $extra = $type->extraPermission();

        return $extra === null || $user->can($extra->value);
    }
}
