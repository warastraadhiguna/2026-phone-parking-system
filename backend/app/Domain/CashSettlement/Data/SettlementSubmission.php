<?php

namespace App\Domain\CashSettlement\Data;

/** A deposit as submitted from the app. The proof photo is optional. */
final class SettlementSubmission
{
    public function __construct(
        public readonly string $settlementUuid,
        public readonly int $amount,
        public readonly ?string $shiftUuid,
        public readonly ?string $notes,
        public readonly ?string $proofContents = null,
        public readonly ?string $proofExtension = null,
    ) {}

    /** @return array<string, mixed> */
    public function fingerprint(): array
    {
        return [
            'settlement_uuid' => $this->settlementUuid,
            'amount' => $this->amount,
            'shift_uuid' => $this->shiftUuid,
            'notes' => $this->notes,
            'proof_sha256' => $this->proofContents === null ? null : hash('sha256', $this->proofContents),
        ];
    }
}
