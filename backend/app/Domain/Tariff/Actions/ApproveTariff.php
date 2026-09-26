<?php

namespace App\Domain\Tariff\Actions;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Models\User;
use App\Domain\Tariff\Enums\TariffStatus;
use App\Domain\Tariff\Models\Tariff;
use App\Support\Errors\RuleViolation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * DRAFT → APPROVED (four-eyes: approver ≠ creator, also a DB constraint).
 *
 * - No backdating: effective_from must be in the future at approval time, so no transaction
 *   already recorded could ever have been priced by this tariff.
 * - The currently open-ended approved tariff of the same scope that started earlier is closed at
 *   the new tariff's start (its only permitted change). A later-starting approved tariff of the
 *   same scope blocks approval; resolve that one first.
 */
final class ApproveTariff
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(Tariff $tariff, User $actor): Tariff
    {
        try {
            return DB::transaction(function () use ($tariff, $actor) {
                $tariff = Tariff::query()->lockForUpdate()->findOrFail($tariff->id);

                if ($tariff->status !== TariffStatus::DRAFT) {
                    throw new RuleViolation('tariff', "Tarif berstatus {$tariff->status->label()} tidak dapat disetujui.");
                }
                if ($tariff->created_by === $actor->id) {
                    throw new RuleViolation('tariff', 'Tarif harus disetujui oleh pengguna lain (bukan pembuatnya).');
                }
                if ($tariff->effective_from->isPast()) {
                    throw new RuleViolation('effective_from', 'Tanggal berlaku sudah lewat. Ubah draf ke waktu mendatang sebelum disetujui.');
                }

                $sameScope = fn (): Builder => Tariff::query()
                    ->where('status', TariffStatus::APPROVED->value)
                    ->where('vehicle_type', $tariff->vehicle_type->value)
                    ->where('location_type', $tariff->location_type->value)
                    ->when(
                        $tariff->location_id === null,
                        fn (Builder $q) => $q->whereNull('location_id'),
                        fn (Builder $q) => $q->where('location_id', $tariff->location_id),
                    );

                if ($sameScope()->where('effective_from', '>=', $tariff->effective_from)->lockForUpdate()->exists()) {
                    throw new RuleViolation('effective_from', 'Sudah ada tarif disetujui untuk cakupan yang sama yang mulai berlaku pada/atau setelah tanggal ini.');
                }

                /** @var Tariff|null $previous */
                $previous = $sameScope()
                    ->where('effective_from', '<', $tariff->effective_from)
                    ->where(fn (Builder $q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $tariff->effective_from))
                    ->lockForUpdate()
                    ->first();

                if ($previous !== null) {
                    if ($previous->effective_until !== null) {
                        throw new RuleViolation('effective_from', "Tarif #{$previous->id} masih berlaku sampai {$previous->effective_until->toIso8601String()}.");
                    }
                    $previous->forceFill(['effective_until' => $tariff->effective_from])->save();
                }

                $tariff->forceFill([
                    'status' => TariffStatus::APPROVED,
                    'approved_by' => $actor->id,
                    'approved_at' => now(),
                ])->save();

                $this->audit->handle(AuditAction::TARIFF_APPROVED, $actor, 'tariff', $tariff->id, [
                    'amount' => $tariff->amount,
                    'effective_from' => $tariff->effective_from->toIso8601String(),
                    'supersedes_tariff_id' => $previous?->id,
                ]);

                return $tariff;
            });
        } catch (QueryException $e) {
            if (in_array($e->getCode(), ['23P01', '23514'], true)) {
                throw new RuleViolation('effective_from', 'Periode tarif bertumpang tindih dengan tarif lain yang sudah disetujui.');
            }
            throw $e;
        }
    }
}
