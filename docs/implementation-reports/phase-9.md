# Implementation Report — Phase 9

```text
PHASE:
Phase 9 — Audit & Anomaly

STATUS:
DONE
```

Date: 2026-09-26. Continued without waiting for approval, as instructed by the owner.

## IMPLEMENTED

- **Immutable audit log, now viewable.** The Control Center **Log Audit** page (`audit.view`) is
  read-only, with filters for action, actor, entity type/ID, request ID and date, and a detail
  view of the metadata.
  - No edit or delete routes exist. The table was already append-only in the DB (Phase 0).
  - Other modules read through `Audit\Services\AuditLogBrowser`, so the architecture rule
    "only the Audit module touches AuditLog" still holds.
- **Geofence anomaly and mock-location signal:** already recorded since Phases 3/4
  (`OUTSIDE_GEOFENCE`, `OUTSIDE_GEOFENCE_START/END`, `MOCK_LOCATION`); they now feed the queue.
- **Impossible movement (basic rule):** `ParkingTransaction\Services\MovementCheck`.
  - It compares a new transaction with the same attendant's closest earlier transaction that has
    a usable GPS fix (by device time).
  - The flag is `IMPOSSIBLE_MOVEMENT` when the jump is at least `movement_min_distance_m` (1000 m)
    **and** faster than `movement_max_speed_kmh` (60 km/h).
  - Both values are settings. GPS fixes worse than `gps_max_accuracy_m` are ignored.
  - It applies to cash (online and offline) and QRIS. It is a signal, never a rejection.
- **Review queue** (FraudReview module):
  - `anomalies:collect` runs every 5 minutes and is idempotent. It collects every review flag on
    transactions and shifts, and every mismatch of the latest reconciliation run of each date,
    into `anomaly_reviews`. It only reads other modules' tables.
  - Severity:
    - HIGH: mock location, impossible movement, late payment, payment amount mismatch, device
      mismatch, reconciliation ERROR;
    - LOW: clock skew, stale offline, overdue, location inactive;
    - MEDIUM: everything else.
  - Control Center **Tinjauan** (`anomalies.view`): sorted by severity, with open counts per
    severity, filters, and a link to the transaction/shift/settlement/attendant.
  - A Supervisor (`anomalies.review`) records **CONFIRMED** or **DISMISSED** with a note (≥ 5
    characters). It is final and audited (`ANOMALY_REVIEWED`), and it is a finding only: money is
    corrected through void, refund or settlement.

## FILES CHANGED

Backend:
- `app/Domain/FraudReview/` (new):
  - `Enums/{ReviewSeverity, ReviewStatus, ReviewSource}`, `Models/AnomalyReview`
  - `Actions/{CollectAnomalies, DecideReview}`, `Console/CollectAnomaliesCommand`
  - `README.md`
- new: `app/Domain/ParkingTransaction/Services/MovementCheck`, `app/Domain/Audit/Services/AuditLogBrowser`
- changed:
  - `ParkingTransaction/Actions/{RecordCashTransaction, CreateQrisTransaction}` (movement check)
  - `ParkingTransaction/Enums/TransactionFlag` (+`IMPOSSIBLE_MOVEMENT`)
  - `SystemConfiguration/Enums/SettingKey` (+2), `Audit/Enums/AuditAction` (+1)
- Admin (new): `Http/Admin/FraudReview/ReviewController`, `Http/Admin/Audit/AuditLogController`
- Wiring (changed): `routes/web.php`, `routes/console.php`, `bootstrap/app.php`
- Migration (new): `2026_09_25_800000_create_anomaly_reviews_table`
- Frontend:
  - new: `Pages/Reviews/Index.tsx`, `Pages/Audit/Index.tsx`
  - changed: `Layouts/AdminLayout.tsx`
- Tests:
  - new: `tests/Feature/Reviews/ReviewQueueTest.php`
  - changed: `tests/Pest.php`, `Operations/SettingsTest.php`
- `composer.json`: `process-timeout` 1200. The suite is now longer than Composer's default of
  300 s on this Windows host.

Docs:
- new: `docs/decisions/0013-review-queue.md`, this report
- updated: `docs/decisions/README.md`, `docs/database/README.md`, `docs/architecture/overview.md`

## DATABASE CHANGES

| Object | Integrity |
|---|---|
| `anomaly_reviews` + `anomaly_reviews_guard()` | Unique `(source, entity_type, entity_id, code)`; CHECK source, severity, status, decision ⇔ decider/time with note ≥ 5 chars; trigger: facts frozen, decided items frozen, no DELETE/TRUNCATE |

The migration is reversible.

## API

No mobile API. Admin web:
- `GET reviews` (`anomalies.view`), `PUT reviews/{id}` (`anomalies.review`);
- `GET audit-logs` (`audit.view`).

## TESTS

- `composer check` → Pint PASS, Larastan level 8 OK, **410 passed (2154 assertions)**. Phase 9
  adds 6 tests.

| ReviewQueueTest case | Covers |
|---|---|
| Impossible movement | 10 km in 1 min is flagged; 110 m jitter is not; a second small hop from the new point is not |
| Realistic trip | 10 km in 2 h (offline) is not flagged |
| Collection | Transaction, shift and reconciliation signals each become one item with the correct severity, key, reference and attendant; a second run adds nothing; a flag added later becomes a new item |
| Decision | Operator gets 403; a note is required; Supervisor confirms (audited); a second decision is refused; UPDATE/DELETE refused in the DB (23001) |
| Queue page | HIGH first, open counts, link to the transaction, operator has no decide right; Executive Viewer 403 |
| Audit viewer | Auditor filters by action; operator 403; no delete route |

Issues found during the phase:
1. The architecture test caught the first audit viewer, which used the `AuditLog` model
   directly from HTTP. It was moved behind `AuditLogBrowser`.
2. Test fixes:
   - the arranged shift already carries a flag of its own, so the test now picks the item by code;
   - an empty table makes an UPDATE touch no rows, so the tests target specific rows.
3. Suite duration grew to about 9 minutes on this machine (about 1.3 s per test for every
   test, including trivial ones). Profiling shows the time is the app booting from files over the
   Windows Docker bind mount, not any query.

## STATIC ANALYSIS

Pint PASS, Larastan level 8 OK, architecture tests pass, TypeScript build passes.

## SECURITY NOTES

- The audit trail stays write-once. The viewer cannot change it, and exports exclude the
  metadata column (Phase 10).
- Review decisions are immutable and attributable; they cannot silently erase a signal.
- The collector's SQL is static; its severity lists are compile-time constants, not input.

## ARCHITECTURE DECISIONS / ADR

- New **ADR-0013** (signals and the review queue).

## KNOWN LIMITATIONS

- Impossible movement compares only with the previous transaction, not with the next one (a
  late offline upload in between is still compared correctly by device time), and not with shift
  start/end positions.
- There is no automatic escalation or notification for HIGH items yet (the Notification module
  is not in the MVP).

## DEVIATIONS FROM APPROVED PLAN

None.

## BLOCKERS

None. Owner items: the thresholds for impossible movement (defaults 60 km/h, 1 km), and who may
review (currently Supervisor).

## NEXT RECOMMENDED PHASE

Phase 10 — Dashboard & Reporting.
