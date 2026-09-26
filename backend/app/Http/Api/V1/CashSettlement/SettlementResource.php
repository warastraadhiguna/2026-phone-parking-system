<?php

namespace App\Http\Api\V1\CashSettlement;

use App\Domain\CashSettlement\Models\CashSettlement;

final class SettlementResource
{
    /** @return array<string, mixed> */
    public static function make(CashSettlement $s): array
    {
        return [
            'settlement_uuid' => $s->settlement_uuid,
            'settlement_number' => $s->settlement_number,
            'status' => $s->status->value,
            'status_label' => $s->status->label(),
            'amount' => $s->amount,
            'verified_amount' => $s->verified_amount,
            'balance_at_submission' => $s->balance_at_submission,
            'notes' => $s->notes,
            'has_proof' => $s->proof_path !== null,
            'submitted_at' => $s->submitted_at->toIso8601String(),
            'decided_at' => $s->decided_at?->toIso8601String(),
            'decision_note' => $s->decision_note,
        ];
    }
}
