# Implementation Report — Phase 8

```text
PHASE:
Phase 8 — Reconciliation

STATUS:
DONE
```

Date: 2026-09-26. Continued without waiting for approval, as instructed by the owner.

## IMPLEMENTED

- **Daily reconciliation** (`RunReconciliation`, `reconciliation:run {date?}`, scheduled 01:30 WIB
  for yesterday):
  - one WIB business date is computed from the source tables (transactions, payments, manual
    refunds, cash ledger, settlements) in **one REPEATABLE READ snapshot**;
  - the result is stored as an **immutable run**;
  - re-running adds a new run; history is kept.
- **Output (master doc §14)** per run, **per attendant** and **per location**:
  - `expected_cash`, `voided_cash`;
  - `ledger_cash_in`, `ledger_reversals`, `cash_deposited`, `cash_outstanding` (day-end balance);
  - `qris_expected`, `qris_paid`, `qris_refunded`, `qris_difference`, `qris_open`;
  - `total_revenue`, transaction count.
- **Mismatch reporting:**
  - **ERROR**, an integrity incident (the command exits 1 and logs an error): cash without a
    ledger entry, ledger ≠ transaction amount, voided cash without reversal, QRIS without a
    payment, QRIS completed but unpaid, payment ≠ transaction amount, verified settlement ≠
    ledger, derived-balance drift;
  - **WARNING**, needs a human: PAID for a cancelled/voided transaction not yet refunded, QR
    stuck more than 1 hour after expiry, settlement pending more than 24 hours.
- **Control Center — Rekonsiliasi:**
  - run history with status badges (Sesuai / perlu tindakan / kesalahan);
  - Finance can run any past date or today;
  - run detail: §14 tiles, mismatches (severity, type, reference, expected and actual), and
    attendant and location tables with a link to a newer run of the same date.
- Audit: `RECONCILIATION_RUN` (actor or system, key totals, mismatch and error counts).

## FILES CHANGED

Backend (new unless noted):
- `app/Domain/Reconciliation/`
  - `Enums/{MismatchCode, Severity, LineDimension}`
  - `Models/{ReconciliationRun, ReconciliationLine, ReconciliationMismatch}`
  - `Internal/ReconciliationCalculator`, `Actions/RunReconciliation`
  - `Console/RunReconciliationCommand`
  - `README.md` (changed)
- Changed: `Audit/Enums/AuditAction` (+1), `bootstrap/app.php`, `routes/console.php`,
  `routes/web.php`
- Admin (new): `Http/Admin/Reconciliation/ReconciliationController`
- Migration (new): `2026_09_25_700000_create_reconciliation_tables`
- Frontend:
  - new: `Pages/Reconciliation/{Index, Show, types}`
  - changed: `Layouts/AdminLayout.tsx`
- Tests:
  - new: `tests/Feature/Reconciliation/ReconciliationTest.php`
  - changed: `tests/Pest.php`

Docs:
- new: `docs/decisions/0012-reconciliation.md`, this report
- updated: `docs/decisions/README.md`, `docs/database/README.md`, `docs/architecture/overview.md`

## DATABASE CHANGES

| Object | Integrity |
|---|---|
| `reconciliation_runs`, `reconciliation_lines`, `reconciliation_mismatches` | All **append-only** (UPDATE/DELETE/TRUNCATE refused); dimension and severity CHECKs; unique line per run × dimension × id |

The migration is reversible: rollback and re-migration were verified.

## API

No mobile API. Admin web:
- `reconciliation`, `reconciliation/{run}` (`reconciliation.view`);
- `POST reconciliation` (`reconciliation.run`, Finance).

## TESTS

- `composer check` → Pint PASS, Larastan level 8 OK, **404 passed (2080 assertions)**. Phase 8
  adds 6 tests.

| ReconciliationTest case | Covers |
|---|---|
| §14 totals | 3 cash (1 voided), QRIS paid and expired, 3000 deposited → exact totals for all 13 metrics, 0 mismatches; attendant and location lines (location has no cash columns); audit |
| QRIS money on a voided transaction | Difference +2000 and warning; after a manual refund, a new run shows 0 while the old run is unchanged (history) |
| Integrity breaches | A cash transaction injected by SQL without a ledger entry, and a tampered derived balance → `CASH_WITHOUT_LEDGER` + `BALANCE_DRIFT` (ERROR), the reference is shown, and the command exits 1 |
| Immutable history | UPDATE/DELETE on runs, lines and mismatches refused (23001) |
| Future dates | Refused |
| Roles | Finance runs; Auditor reads and cannot run; Executive Viewer has no access |

- **Mutation check:** counting voided cash as expected makes the §14 totals test fail.
- On the dev stack: `reconciliation:run` for 2026-09-25 and 2026-09-26 gave 0 mismatches on the
  data from the Phase 4–7 smoke and e2e runs.

Issue found during the phase: the immutability test first ran on an empty run, where an UPDATE
touches no rows, so the row trigger cannot fire. The test now creates data first.

## STATIC ANALYSIS

- Pint PASS, Larastan level 8 OK, architecture tests pass
  (`Reconciliation\Internal` is private), TypeScript build passes.

## SECURITY NOTES

- Reconciliation only reads financial data and writes append-only snapshots. It never
  "corrects" anything: corrections stay in their own audited workflows (void, refund,
  settlement, balance rebuild).
- The window timestamps are generated by the server and written into the SQL as literals (they
  are never user input; the date is validated by `date_format:Y-m-d`). PDO native prepares do
  not allow a repeated named parameter.
- Integrity errors raise an error log and a non-zero exit code for monitoring.

## ARCHITECTURE DECISIONS / ADR

- New **ADR-0012** (daily reconciliation).

## KNOWN LIMITATIONS

- Mismatches cannot yet be acknowledged or resolved. That is the Phase 9 review queue.
- Midtrans settlement statements are not imported. `qris_paid` reflects our verified payments.
- Periods longer than one day (week, month) come with Phase 10 reports; they can be summed from
  daily runs.

## DEVIATIONS FROM APPROVED PLAN

None.

## BLOCKERS

None. Owner item: the official reconciliation report format (§62 #12).

## NEXT RECOMMENDED PHASE

Phase 9 — Audit & Anomaly (review queue for review flags and reconciliation mismatches; audit log
viewer).
