<?php

namespace App\Domain\Tariff\Actions;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Models\User;
use App\Domain\Tariff\Enums\TariffStatus;
use App\Domain\Tariff\Models\Tariff;
use App\Support\Errors\RuleViolation;
use Illuminate\Support\Facades\DB;

/** DRAFT → REJECTED (final). Also how a creator withdraws their own draft. */
final class RejectTariff
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(Tariff $tariff, string $reason, User $actor): Tariff
    {
        return DB::transaction(function () use ($tariff, $reason, $actor) {
            $tariff = Tariff::query()->lockForUpdate()->findOrFail($tariff->id);
            if ($tariff->status !== TariffStatus::DRAFT) {
                throw new RuleViolation('reason', "Tarif berstatus {$tariff->status->label()} tidak dapat ditolak.");
            }

            $tariff->forceFill(['status' => TariffStatus::REJECTED, 'rejection_reason' => trim($reason)])->save();

            $this->audit->handle(AuditAction::TARIFF_REJECTED, $actor, 'tariff', $tariff->id, ['reason' => trim($reason)]);

            return $tariff;
        });
    }
}
