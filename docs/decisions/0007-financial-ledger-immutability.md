# ADR-0007: Financial ledger and audit immutability

- Status: Accepted. Foundation in Phase 0; tables in Phase 1 (audit) and Phase 4 (ledger).
- Date: 2026-09-25

## Context

Cash is controlled through expected, deposited and outstanding amounts (master doc §11–§13).
Ledger and audit records must never be edited or deleted (§3.3, §12, §27). An operational
balance is needed for fast reads, but it must not become a second, drifting truth.

## Decision

### Source of truth

- **`cash_ledger_entries` is the authoritative history of cash movement.** Entry types:
  `PARKING_CASH_IN`, `SETTLEMENT_OUT`, `ADJUSTMENT`, `REVERSAL`. Amounts are signed
  `bigint` rupiah.
- **`attendant_cash_balances` is derived**: one row per attendant, holding the current
  balance and the last ledger entry ID, for fast reads.
- Every ledger insert runs in a DB transaction that first takes `SELECT … FOR UPDATE` on the
  attendant's balance row. It then writes the entry, with `balance_after` computed from the
  locked balance, and updates the balance row. Concurrent inserts for one attendant are serialised.
- **Rebuild:** an operational command (Phase 4) recomputes every balance from the ledger
  (`SUM(amount)` per attendant) under lock, reports differences, and corrects the derived
  row. The ledger itself is never changed. A scheduled consistency check flags drift.
- Corrections are **new entries** (`REVERSAL`, `ADJUSTMENT`) that reference the original.
  A voided cash transaction produces a `REVERSAL` (§46 rule 10).

### Immutability protection (two layers)

1. **Application:** no update or delete code paths, no admin screen, no API. Models for these
   tables expose creation only.
2. **Database:** `App\Support\Database\AppendOnlyTable::protect($table)` installs
   `BEFORE UPDATE OR DELETE` (row) and `BEFORE TRUNCATE` (statement) triggers calling
   `forbid_append_only_mutation()`. That function raises SQLSTATE `23001` (`restrict_violation`).
   It is created in Phase 0 and tested in `PostgresFoundationTest`. It applies to
   `cash_ledger_entries`, `audit_logs` and later append-only tables (for example
   `payment_webhook_events`).
3. **Privileges (production, Phase 11):** the application's runtime DB role gets only
   `SELECT, INSERT` on these tables. Schema changes run under a separate migration role that
   owns the tables.

### Interaction with operations

| Situation | How it works |
|---|---|
| Migrations adding columns or indexes | Allowed: triggers block row changes, not DDL. Run under the migration role. |
| Migration that must rewrite rows (rare) | Must be a reviewed, dedicated migration that calls `AppendOnlyTable::release()`, performs the documented change, calls `protect()` again in the same transaction, and writes an audit record describing it. Requires owner approval. |
| Backup (`pg_dump`) | Unaffected: reads only. |
| Restore (`pg_restore` / `psql`) | Unaffected: restore inserts rows into fresh tables. Triggers are recreated with the schema. |
| Test suite (`RefreshDatabase`) | Unaffected: it rolls back transactions and does not delete or truncate protected tables. |
| Data retention / archiving | Decided with the owner (master doc §62 #13). It would be a documented, approved migration-level procedure, never an app feature. |
| Emergency recovery | Performed by a DBA under the migration role, with change ticket and audit entry. There is **no hidden API, admin screen or config flag** that bypasses the triggers. |

`session_replication_role = replica` also disables triggers but requires superuser rights.
The application role must never have them.

## Consequences

- History cannot be changed by application bugs or ordinary users. Changes need privileged,
  visible, documented actions.
- The balance can always be proven against, and rebuilt from, the ledger.
- Every ledger write costs one row lock per attendant. That is fine at the expected volume
  (one attendant produces serial transactions).

## Implementation notes (Phase 4)

- `App\Domain\CashLedger\Internal\LedgerWriter` is the only writer. It refuses to run outside
  a DB transaction. It inserts or locks the attendant's `attendant_cash_balances` row
  (`SELECT … FOR UPDATE`), writes the entry with `balance_after`, and updates the derived row.
- Public entry points: `RecordCashIn` (called in the same transaction that creates a cash
  parking transaction) and `ReverseLedgerEntry` (void approval). `SETTLEMENT_OUT` is written by `RecordSettlementOut` when finance verifies a settlement (Phase 7, [ADR-0011](0011-cash-settlement.md)).
- Database guarantees beyond the append-only triggers:
  - one `PARKING_CASH_IN` per transaction and each entry reversed at most once (partial unique indexes);
  - type/sign/reference CHECKs (cash-in > 0 with a transaction; settlement-out < 0 with a
    settlement; reversal ⇔ `reverses_entry_id`; amount ≠ 0).
- `php artisan cash:verify-balances` (daily at 01:00 WIB) compares every derived balance with
  `SUM(ledger)` and logs an error on mismatch. `--fix` rebuilds the mismatching rows under a
  table lock and records `CASH_BALANCES_REBUILT`. The ledger is never modified.
- `parking_transactions` is protected by its own trigger. Identity, money, time and position
  columns are frozen after insert, and status may only follow master doc §47. Transactions are
  never deleted or truncated.