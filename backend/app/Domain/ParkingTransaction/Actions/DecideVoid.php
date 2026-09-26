<?php

namespace App\Domain\ParkingTransaction\Actions;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\CashLedger\Actions\ReverseLedgerEntry;
use App\Domain\CashLedger\Enums\LedgerEntryType;
use App\Domain\CashLedger\Models\CashLedgerEntry;
use App\Domain\Identity\Models\User;
use App\Domain\ParkingTransaction\Enums\PaymentMethod;
use App\Domain\ParkingTransaction\Enums\TransactionStatus;
use App\Domain\ParkingTransaction\Enums\VoidRequestStatus;
use App\Domain\ParkingTransaction\Models\ParkingTransaction;
use App\Domain\ParkingTransaction\Models\VoidRequest;
use App\Support\Errors\RuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * A supervisor decides a void request (master doc §28; §46 rule 10).
 *
 * - Approve: VOID_REQUESTED → VOIDED. For CASH, the cash-in ledger entry is reversed (the original
 *   transaction and entry stay). QRIS payments stay PAID (ADR-0006; refunds are separate records).
 * - Reject: VOID_REQUESTED → COMPLETED.
 * Four eyes: the requester cannot decide their own request (also a DB constraint).
 */
final class DecideVoid
{
    public function __construct(
        private readonly ReverseLedgerEntry $reverse,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(VoidRequest $request, bool $approve, User $supervisor, ?string $note): VoidRequest
    {
        return DB::transaction(function () use ($request, $approve, $supervisor, $note) {
            $request = VoidRequest::query()->lockForUpdate()->findOrFail($request->id);
            $transaction = ParkingTransaction::query()->lockForUpdate()->findOrFail($request->transaction_id);

            if ($request->status !== VoidRequestStatus::PENDING) {
                throw new RuleViolation('decision_note', 'Pengajuan ini sudah diputuskan.');
            }
            if ($request->requested_by === $supervisor->id) {
                throw new RuleViolation('decision_note', 'Pengajuan harus diputuskan oleh pengguna lain (bukan pengaju).');
            }

            $request->forceFill([
                'status' => $approve ? VoidRequestStatus::APPROVED : VoidRequestStatus::REJECTED,
                'decided_by' => $supervisor->id,
                'decided_at' => now(),
                'decision_note' => $note !== null ? trim($note) : null,
            ])->save();

            $reversal = null;
            if ($approve) {
                $transaction->forceFill(['status' => TransactionStatus::VOIDED])->save();

                if ($transaction->payment_method === PaymentMethod::CASH) {
                    $cashIn = CashLedgerEntry::query()
                        ->where('transaction_id', $transaction->id)
                        ->where('type', LedgerEntryType::PARKING_CASH_IN->value)
                        ->firstOrFail();
                    $reversal = $this->reverse->handle($cashIn, "VOID {$transaction->transaction_number}", $supervisor);
                }
            } else {
                $transaction->forceFill(['status' => TransactionStatus::COMPLETED])->save();
            }

            $this->audit->handle($approve ? AuditAction::VOID_APPROVED : AuditAction::VOID_REJECTED, $supervisor, 'parking_transaction', $transaction->transaction_uuid, array_filter([
                'void_request_id' => $request->id,
                'note' => $note,
                'reversal_entry_id' => $reversal?->id,
                'amount' => $transaction->charged_tariff_amount,
            ], fn ($v) => $v !== null));

            return $request;
        });
    }
}
