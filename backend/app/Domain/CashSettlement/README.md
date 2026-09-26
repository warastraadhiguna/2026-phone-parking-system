# CashSettlement

Cash deposit submission and finance verification (Phase 7, ADR-0011).

- `Actions/SubmitSettlement`: the attendant declares a deposit (idempotent, no money moves).
- `Actions/CancelSettlement`: the attendant withdraws an undecided deposit.
- `Actions/DecideSettlement`: finance verifies the counted amount (ledger SETTLEMENT_OUT via
  `CashLedger\Actions\RecordSettlementOut`) or rejects.

Private to this module: `Internal/`. See docs/architecture/overview.md#module-boundaries.
