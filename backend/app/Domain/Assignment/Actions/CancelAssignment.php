<?php

namespace App\Domain\Assignment\Actions;

use App\Domain\Assignment\Enums\AssignmentPhase;
use App\Domain\Assignment\Enums\AssignmentStatus;
use App\Domain\Assignment\Models\Assignment;
use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Models\User;
use App\Support\Errors\RuleViolation;
use App\Support\Time\BusinessTime;
use Illuminate\Support\Facades\DB;

/**
 * Withdraws an assignment that has not started yet. A started assignment is ended instead,
 * so that work already done stays attributable to it.
 */
final class CancelAssignment
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(Assignment $assignment, string $reason, ?User $actor): Assignment
    {
        return DB::transaction(function () use ($assignment, $reason, $actor) {
            $assignment = Assignment::query()->lockForUpdate()->findOrFail($assignment->id);

            if ($assignment->phaseOn(BusinessTime::today()) !== AssignmentPhase::SCHEDULED) {
                throw new RuleViolation('reason', 'Hanya penugasan yang belum dimulai yang dapat dibatalkan. Akhiri penugasan yang sedang berjalan.');
            }

            $assignment->forceFill(['status' => AssignmentStatus::CANCELLED, 'cancel_reason' => trim($reason)])->save();

            $this->audit->handle(AuditAction::ASSIGNMENT_CANCELLED, $actor, 'assignment', $assignment->id, ['reason' => trim($reason)]);

            return $assignment;
        });
    }
}
