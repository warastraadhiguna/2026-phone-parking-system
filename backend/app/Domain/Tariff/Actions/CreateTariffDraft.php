<?php

namespace App\Domain\Tariff\Actions;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Models\User;
use App\Domain\Tariff\Data\TariffData;
use App\Domain\Tariff\Enums\TariffStatus;
use App\Domain\Tariff\Internal\TariffRules;
use App\Domain\Tariff\Models\Tariff;
use Illuminate\Support\Facades\DB;

/** A new tariff starts as DRAFT and has no effect until another authorised user approves it. */
final class CreateTariffDraft
{
    public function __construct(
        private readonly TariffRules $rules,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(TariffData $data, User $actor): Tariff
    {
        $this->rules->assertScopeConsistent($data);

        return DB::transaction(function () use ($data, $actor) {
            $tariff = Tariff::create([
                ...$data->toAttributes(),
                'status' => TariffStatus::DRAFT,
                'created_by' => $actor->id,
            ]);

            $this->audit->handle(AuditAction::TARIFF_CREATED, $actor, 'tariff', $tariff->id, [
                'vehicle_type' => $data->vehicleType->value,
                'location_type' => $data->locationType->value,
                'location_id' => $data->locationId,
                'amount' => $data->amount,
                'effective_from' => $data->effectiveFrom->toIso8601String(),
                'regulation_reference' => $tariff->regulation_reference,
            ]);

            return $tariff;
        });
    }
}
