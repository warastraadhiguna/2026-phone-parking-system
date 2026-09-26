<?php

namespace App\Domain\Assignment\Actions;

use App\Domain\Assignment\Enums\AssignmentPhase;
use App\Domain\Assignment\Models\Assignment;
use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Models\User;
use App\Support\Errors\RuleViolation;
use App\Support\Time\BusinessTime;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Sets (or moves) the last day of a running or scheduled assignment. History is never
 * rewritten: the last day cannot be before today.
 */
final class EndAssignment
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(Assignment $assignment, string $until, ?User $actor): Assignment
    {
        try {
            return DB::transaction(function () use ($assignment, $until, $actor) {
                $assignment = Assignment::query()->lockForUpdate()->findOrFail($assignment->id);
                $today = BusinessTime::today();
                $phase = $assignment->phaseOn($today);

                if (in_array($phase, [AssignmentPhase::CANCELLED, AssignmentPhase::FINISHED], true)) {
                    throw new RuleViolation('effective_until', "Penugasan berstatus {$phase->label()} tidak dapat diubah.");
                }
                if ($until < $today) {
                    throw new RuleViolation('effective_until', 'Tanggal selesai tidak boleh sebelum hari ini.');
                }
                if ($until < $assignment->effective_from->toDateString()) {
                    throw new RuleViolation('effective_until', 'Tanggal selesai tidak boleh sebelum tanggal mulai.');
                }

                $previous = $assignment->effective_until?->toDateString();
                $assignment->forceFill(['effective_until' => $until])->save();

                $this->audit->handle(AuditAction::ASSIGNMENT_ENDED, $actor, 'assignment', $assignment->id, [
                    'from' => $previous,
                    'to' => $until,
                ]);

                return $assignment;
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23P01') {
                throw new RuleViolation('effective_until', 'Periode baru bertumpang tindih dengan penugasan lain juru parkir tersebut.');
            }
            throw $e;
        }
    }
}
