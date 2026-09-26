<?php

namespace App\Domain\CashSettlement\Actions;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\CashLedger\Actions\RecordSettlementOut;
use App\Domain\CashSettlement\Enums\SettlementStatus;
use App\Domain\CashSettlement\Models\CashSettlement;
use App\Domain\Identity\Models\User;
use App\Support\Errors\RuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * Finance decides a submitted deposit (master doc §13, Scenario D; ADR-0011).
 *
 * - Verify: finance enters the amount actually counted. The ledger gets one SETTLEMENT_OUT of
 *   that amount (never more than the attendant holds); what remains is outstanding cash. A
 *   counted amount different from the declared one needs a note.
 * - Reject: no money moves; a reason is required.
 * Four eyes: the submitter can never decide (also a DB CHECK). Decided settlements are frozen.
 */
final class DecideSettlement
{
    public function __construct(
        private readonly RecordSettlementOut $settlementOut,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function verify(CashSettlement $settlement, User $verifier, int $countedAmount, ?string $note): CashSettlement
    {
        $note = $note !== null && trim($note) !== '' ? trim($note) : null;

        return DB::transaction(function () use ($settlement, $verifier, $countedAmount, $note) {
            $settlement = $this->lockOpen($settlement, $verifier);

            if ($countedAmount !== $settlement->amount && $note === null) {
                throw new RuleViolation('decision_note', 'Jumlah diterima berbeda dengan jumlah yang diajukan: catatan wajib diisi.');
            }

            $entry = $this->settlementOut->handle($settlement, $countedAmount, $verifier);

            $settlement->forceFill([
                'status' => SettlementStatus::VERIFIED,
                'verified_amount' => $countedAmount,
                'decided_by' => $verifier->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();

            $this->audit->handle(AuditAction::SETTLEMENT_VERIFIED, $verifier, 'cash_settlement', $settlement->settlement_uuid, array_filter([
                'settlement_number' => $settlement->settlement_number,
                'declared_amount' => $settlement->amount,
                'verified_amount' => $countedAmount,
                'difference' => $countedAmount - $settlement->amount,
                'ledger_entry_id' => $entry->id,
                'balance_after' => $entry->balance_after,
                'note' => $note,
            ], fn ($v) => $v !== null));

            return $settlement;
        });
    }

    public function reject(CashSettlement $settlement, User $verifier, string $reason): CashSettlement
    {
        if (trim($reason) === '') {
            throw new RuleViolation('decision_note', 'Alasan penolakan wajib diisi.');
        }

        return DB::transaction(function () use ($settlement, $verifier, $reason) {
            $settlement = $this->lockOpen($settlement, $verifier);

            $settlement->forceFill([
                'status' => SettlementStatus::REJECTED,
                'decided_by' => $verifier->id,
                'decided_at' => now(),
                'decision_note' => trim($reason),
            ])->save();

            $this->audit->handle(AuditAction::SETTLEMENT_REJECTED, $verifier, 'cash_settlement', $settlement->settlement_uuid, [
                'settlement_number' => $settlement->settlement_number,
                'declared_amount' => $settlement->amount,
                'reason' => trim($reason),
            ]);

            return $settlement;
        });
    }

    private function lockOpen(CashSettlement $settlement, User $verifier): CashSettlement
    {
        $settlement = CashSettlement::query()->lockForUpdate()->findOrFail($settlement->id);

        if ($settlement->status !== SettlementStatus::SUBMITTED) {
            throw new RuleViolation('decision_note', 'Setoran ini sudah diputuskan atau dibatalkan.');
        }
        if ($settlement->submitted_by === $verifier->id) {
            throw new RuleViolation('decision_note', 'Setoran harus diverifikasi oleh pengguna lain (bukan pengaju).');
        }

        return $settlement;
    }
}
