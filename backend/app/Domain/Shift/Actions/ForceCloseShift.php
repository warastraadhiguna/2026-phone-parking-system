<?php

namespace App\Domain\Shift\Actions;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Models\User;
use App\Domain\Shift\Enums\ShiftStatus;
use App\Domain\Shift\Models\Shift;
use App\Support\Errors\RuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * A supervisor closes a shift the attendant did not end (e.g. overdue, lost phone).
 * Device end-time and end-position stay empty: they were never reported.
 */
final class ForceCloseShift
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(Shift $shift, string $reason, User $actor): Shift
    {
        return DB::transaction(function () use ($shift, $reason, $actor) {
            $shift = Shift::query()->lockForUpdate()->findOrFail($shift->id);
            if (! $shift->isOpen()) {
                throw new RuleViolation('reason', "Shift berstatus {$shift->status->label()} tidak dapat ditutup paksa.");
            }

            $shift->forceFill([
                'status' => ShiftStatus::FORCED_CLOSED,
                'ended_at_server' => now(),
                'force_closed_by' => $actor->id,
                'close_reason' => trim($reason),
            ])->save();

            $this->audit->handle(AuditAction::SHIFT_FORCE_CLOSED, $actor, 'shift', $shift->shift_uuid, [
                'attendant_id' => $shift->attendant_id,
                'reason' => trim($reason),
                'open_minutes' => (int) $shift->started_at_server->diffInMinutes(now()),
            ]);

            return $shift;
        });
    }
}
