<?php

namespace App\Domain\Shift\Actions;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Shift\Enums\ShiftFlag;
use App\Domain\Shift\Enums\ShiftStatus;
use App\Domain\Shift\Models\Shift;
use App\Domain\SystemConfiguration\Enums\SettingKey;
use App\Domain\SystemConfiguration\Services\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Hourly job: OPEN shifts older than max_open_shift_hours get the OVERDUE flag (once).
 * Closing them stays a supervisor decision (ForceCloseShift).
 */
final class FlagOverdueShifts
{
    public function __construct(
        private readonly Settings $settings,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(): int
    {
        $cutoff = now()->subHours($this->settings->int(SettingKey::MAX_OPEN_SHIFT_HOURS));
        $flagged = 0;

        Shift::query()
            ->where('status', ShiftStatus::OPEN->value)
            ->where('started_at_server', '<', $cutoff)
            ->whereRaw('NOT (review_flags @> ?::jsonb)', [json_encode([ShiftFlag::OVERDUE->value])])
            ->orderBy('id')
            ->each(function (Shift $shift) use (&$flagged) {
                DB::transaction(function () use ($shift) {
                    $shift->withFlags([ShiftFlag::OVERDUE])->save();
                    $this->audit->handle(AuditAction::SHIFT_FLAGGED, null, 'shift', $shift->shift_uuid, ['flag' => ShiftFlag::OVERDUE->value]);
                });
                $flagged++;
            });

        return $flagged;
    }
}
