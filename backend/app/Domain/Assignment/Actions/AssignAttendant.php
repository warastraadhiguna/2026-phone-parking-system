<?php

namespace App\Domain\Assignment\Actions;

use App\Domain\Assignment\Enums\AssignmentStatus;
use App\Domain\Assignment\Models\Assignment;
use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Models\User;
use App\Domain\ParkingAttendant\Models\ParkingAttendant;
use App\Domain\ParkingLocation\Models\ParkingLocation;
use App\Support\Errors\RuleViolation;
use App\Support\Time\BusinessTime;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Assigns an attendant to a location for an inclusive date range (WIB).
 * No backdating; at most one location per attendant per day (DB exclusion constraint).
 */
final class AssignAttendant
{
    private const EXCLUSION_VIOLATION = '23P01';

    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(ParkingAttendant $attendant, ParkingLocation $location, string $from, ?string $until, ?User $actor): Assignment
    {
        if (! $attendant->isOperational()) {
            throw new RuleViolation('attendant_id', 'Juru parkir tidak aktif sehingga tidak dapat ditugaskan.');
        }
        if (! $location->isOperational()) {
            throw new RuleViolation('location_id', 'Lokasi tidak aktif sehingga tidak dapat menerima penugasan.');
        }
        if ($from < BusinessTime::today()) {
            throw new RuleViolation('effective_from', 'Tanggal mulai tidak boleh sebelum hari ini.');
        }
        if ($until !== null && $until < $from) {
            throw new RuleViolation('effective_until', 'Tanggal selesai tidak boleh sebelum tanggal mulai.');
        }

        try {
            return DB::transaction(function () use ($attendant, $location, $from, $until, $actor) {
                $assignment = Assignment::create([
                    'attendant_id' => $attendant->id,
                    'location_id' => $location->id,
                    'effective_from' => $from,
                    'effective_until' => $until,
                    'status' => AssignmentStatus::ACTIVE,
                    'created_by' => $actor?->id,
                ]);

                $this->audit->handle(AuditAction::ASSIGNMENT_CREATED, $actor, 'assignment', $assignment->id, [
                    'attendant_id' => $attendant->id,
                    'location_id' => $location->id,
                    'effective_from' => $from,
                    'effective_until' => $until,
                ]);

                return $assignment;
            });
        } catch (QueryException $e) {
            if ($e->getCode() === self::EXCLUSION_VIOLATION) {
                throw new RuleViolation('effective_from', 'Periode ini bertumpang tindih dengan penugasan lain juru parkir tersebut.');
            }
            throw $e;
        }
    }
}
