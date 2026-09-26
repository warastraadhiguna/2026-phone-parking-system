<?php

namespace App\Domain\Reporting\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Reporting\Enums\ExportFormat;
use App\Domain\Reporting\Enums\ExportStatus;
use App\Domain\Reporting\Enums\ReportType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $export_uuid
 * @property int $requested_by
 * @property ReportType $report_type
 * @property ExportFormat $format
 * @property array<string, mixed> $params
 * @property ExportStatus $status
 * @property string|null $file_path
 * @property int|null $row_count
 * @property string|null $error
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable $created_at
 */
class ReportExport extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'report_type' => ReportType::class,
            'format' => ExportFormat::class,
            'status' => ExportStatus::class,
            'params' => 'array',
            'row_count' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
