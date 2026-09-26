<?php

namespace App\Domain\ParkingTransaction\Actions;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Models\User;
use App\Domain\ParkingTransaction\Enums\TransactionStatus;
use App\Domain\ParkingTransaction\Enums\VoidChannel;
use App\Domain\ParkingTransaction\Enums\VoidRequestStatus;
use App\Domain\ParkingTransaction\Models\ParkingTransaction;
use App\Domain\ParkingTransaction\Models\VoidRequest;
use App\Support\Errors\RuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * COMPLETED → VOID_REQUESTED (master doc §28). Money and ledger are untouched until a supervisor
 * approves. Requested by the attendant (own transactions only) or by an operator.
 */
final class RequestVoid
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(ParkingTransaction $transaction, User $requester, VoidChannel $channel, string $reason): VoidRequest
    {
        return DB::transaction(function () use ($transaction, $requester, $channel, $reason) {
            $transaction = ParkingTransaction::query()->lockForUpdate()->findOrFail($transaction->id);

            if (! $transaction->status->canTransitionTo(TransactionStatus::VOID_REQUESTED)) {
                throw new RuleViolation('reason', "Transaksi berstatus {$transaction->status->label()} tidak dapat diajukan pembatalan.");
            }

            $transaction->forceFill(['status' => TransactionStatus::VOID_REQUESTED])->save();
            $request = VoidRequest::create([
                'transaction_id' => $transaction->id,
                'requested_by' => $requester->id,
                'channel' => $channel,
                'reason' => trim($reason),
                'status' => VoidRequestStatus::PENDING,
            ]);

            $this->audit->handle(AuditAction::VOID_REQUESTED, $requester, 'parking_transaction', $transaction->transaction_uuid, [
                'void_request_id' => $request->id,
                'channel' => $channel->value,
                'reason' => trim($reason),
            ]);

            return $request;
        });
    }
}
