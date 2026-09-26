<?php

namespace App\Domain\CashSettlement\Actions;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\CashSettlement\Enums\SettlementStatus;
use App\Domain\CashSettlement\Models\CashSettlement;
use App\Domain\Identity\Models\User;
use App\Support\Errors\ApiException;
use App\Support\Errors\ErrorCode;
use Illuminate\Support\Facades\DB;

/** The attendant withdraws a submission that finance has not decided yet. No money moved. */
final class CancelSettlement
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(CashSettlement $settlement, User $actor): CashSettlement
    {
        return DB::transaction(function () use ($settlement, $actor) {
            $settlement = CashSettlement::query()->lockForUpdate()->findOrFail($settlement->id);

            if ($settlement->status !== SettlementStatus::SUBMITTED) {
                throw new ApiException(ErrorCode::CONFLICT, "Setoran berstatus {$settlement->status->label()} tidak dapat dibatalkan.");
            }
            if ($settlement->submitted_by !== $actor->id) {
                throw new ApiException(ErrorCode::FORBIDDEN, 'Hanya pengaju yang dapat membatalkan setoran ini.');
            }

            $settlement->forceFill(['status' => SettlementStatus::CANCELLED])->save();
            $this->audit->handle(AuditAction::SETTLEMENT_CANCELLED, $actor, 'cash_settlement', $settlement->settlement_uuid, [
                'amount' => $settlement->amount,
            ]);

            return $settlement;
        });
    }
}
