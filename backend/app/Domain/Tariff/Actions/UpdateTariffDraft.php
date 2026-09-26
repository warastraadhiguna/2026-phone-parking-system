<?php

namespace App\Domain\Tariff\Actions;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Models\User;
use App\Domain\Tariff\Data\TariffData;
use App\Domain\Tariff\Enums\TariffStatus;
use App\Domain\Tariff\Internal\TariffRules;
use App\Domain\Tariff\Models\Tariff;
use App\Support\Database\ChangeSet;
use App\Support\Errors\RuleViolation;
use Illuminate\Support\Facades\DB;

/** Only drafts can be edited. Approved tariffs are superseded by new versions instead. */
final class UpdateTariffDraft
{
    public function __construct(
        private readonly TariffRules $rules,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(Tariff $tariff, TariffData $data, User $actor): Tariff
    {
        $this->rules->assertScopeConsistent($data);

        return DB::transaction(function () use ($tariff, $data, $actor) {
            $tariff = Tariff::query()->lockForUpdate()->findOrFail($tariff->id);
            if ($tariff->status !== TariffStatus::DRAFT) {
                throw new RuleViolation('amount', 'Hanya tarif berstatus draf yang dapat diubah. Buat versi tarif baru.');
            }

            $tariff->fill($data->toAttributes());
            $changes = ChangeSet::of($tariff);
            if ($changes === []) {
                return $tariff;
            }
            $tariff->save();

            $this->audit->handle(AuditAction::TARIFF_CHANGED, $actor, 'tariff', $tariff->id, ['changes' => $changes]);

            return $tariff;
        });
    }
}
