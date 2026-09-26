# ADR-0012: Daily reconciliation

- Status: Accepted (Phase 8)
- Date: 2026-09-26

## Context

Master doc §14 requires matching parking transactions, payment records, the cash ledger and
cash settlements. The output must include expected_cash, cash_deposited, cash_outstanding,
qris_expected, qris_paid, qris_difference and total_revenue. Phase 8 asks for daily, attendant
and location reconciliation plus mismatch reporting. Reconciliation data may never be deleted
(§3.3).

## Decision

1. **Immutable snapshots.** A run covers one WIB business date. Its totals, lines (per attendant,
   per location) and mismatches are computed and stored in **one REPEATABLE READ transaction**.
   All three tables are append-only. Running a date again adds a new run, and old runs stay as
   history.
2. **Independent calculation.** `Reconciliation\Internal\ReconciliationCalculator` reads the
   source tables with SQL. It does not use application-derived values, so it can prove them.
3. **Day basis:** `transaction_time_server`, which is the same clock as the ledger's `created_at`
   (written in the same DB transaction). Offline work counts on the day it reached the server.
4. **Definitions:**
   - `expected_cash` / `qris_expected`: COMPLETED + VOID_REQUESTED;
   - `voided_cash`: VOIDED cash;
   - `qris_paid` / `qris_refunded`: PAID payments and manual refunds for the day's transactions;
   - `qris_difference = (paid − refunded) − expected`;
   - `cash_outstanding`: ledger balance at day end;
   - `total_revenue = expected_cash + qris_expected`.
5. **Mismatches have two severities.**
   - **ERROR:** records contradict each other. This should be impossible (DB constraints, single
     writers) and is treated as an incident. The command exits 1 and logs an error.
     Codes: cash without ledger, ledger amount ≠ transaction, voided cash without reversal, QRIS
     without payment, QRIS completed but unpaid, payment amount ≠ transaction, verified
     settlement ≠ ledger, derived balance drift.
   - **WARNING:** consistent but needs a human: PAID for a cancelled or voided transaction that
     is not refunded, QR waiting more than 1 hour after expiry, settlement pending more than 24
     hours.
6. **Schedule:** daily at 01:30 WIB for the previous day (after `cash:verify-balances`). Finance
   (`reconciliation.run`) can run any past date or today. Everyone with `reconciliation.view`
   can read.

## Consequences

- Every figure in a report can be traced to a stored run, and re-running never hides history.
- Acknowledging or resolving mismatches is not part of this phase. It belongs to the review
  queue (Phase 9).
- Provider settlement reports (Midtrans statements) are not imported yet. `qris_paid` is based on
  our verified payment records.
