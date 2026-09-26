<?php

namespace App\Domain\ParkingAttendant\Actions;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Models\User;
use App\Domain\ParkingAttendant\Models\ParkingAttendant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores an attendant photo on the private disk. Photos are served only through an
 * authorized route, never publicly. The previous photo file is kept (history).
 */
final class SetAttendantPhoto
{
    public const DISK = 'local';

    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(ParkingAttendant $attendant, string $contents, string $extension, ?User $actor): ParkingAttendant
    {
        $path = sprintf('attendants/%d/%s.%s', $attendant->id, Str::uuid(), strtolower($extension));
        Storage::disk(self::DISK)->put($path, $contents);

        return DB::transaction(function () use ($attendant, $path, $actor) {
            $attendant = ParkingAttendant::query()->lockForUpdate()->findOrFail($attendant->id);
            $previous = $attendant->photo_path;
            $attendant->forceFill(['photo_path' => $path])->save();

            $this->audit->handle(AuditAction::ATTENDANT_CHANGED, $actor, 'parking_attendant', $attendant->id, [
                'changes' => ['photo_path' => ['from' => $previous, 'to' => $path]],
            ]);

            return $attendant;
        });
    }
}
