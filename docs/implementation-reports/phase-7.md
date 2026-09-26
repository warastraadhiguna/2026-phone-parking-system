# Implementation Report — Phase 7

```text
PHASE:
Phase 7 — Settlement

STATUS:
DONE
```

Date: 2026-09-26. Continued without waiting for approval, as instructed by the owner.

## IMPLEMENTED

- **Cash summary:** expected (collected) − deposited = outstanding, always from the ledger
  (`CashLedger\Services\CashSummary`).
  - Available for all time, for one shift, and for one WIB business day, because the settlement
    policy (per shift or per day) is still an owner decision.
  - App: `GET /api/v1/cash/summary` (also returns the open submission).
  - Control Center: total outstanding and outstanding per attendant.
- **Submit settlement** `POST /api/v1/settlements`:
  - idempotent on `settlement_uuid`; the fingerprint includes the SHA-256 of the proof photo;
  - optional shift and note; optional proof photo, stored on the private disk;
  - amount ≤ server cash balance; one open submission per attendant;
  - **moves no money**;
  - the attendant can withdraw it (`/cancel`) until finance decides.
- **Finance verification** (Control Center **Setoran**, `settlements.verify` = Finance):
  - **verify** with the **counted** amount. It writes one `SETTLEMENT_OUT` of that amount
    (`RecordSettlementOut`, under the balance row lock, never more than held). A different
    amount than declared needs a note, and the rest stays outstanding (Scenario D);
  - **reject** with a reason; no money moves;
  - four eyes (the submitter can never decide); decided settlements are frozen.
- **Outstanding cash:** the list page shows pending first, and the totals (outstanding,
  pending count and amount, verified today) and outstanding per attendant. The detail page shows
  the attendant's ledger summary, the proof, and the resulting ledger entry.
- **Android:** "Setor kas" shows expected / deposited / outstanding, submits a deposit (online,
  only after everything is synced; a lost answer is retried unchanged), and lets the attendant
  cancel a pending one.
- Audit: `SETTLEMENT_SUBMITTED`, `SETTLEMENT_VERIFIED` (declared, verified, difference, ledger
  entry, balance after), `SETTLEMENT_REJECTED`, `SETTLEMENT_CANCELLED`.

## FILES CHANGED

Backend (new unless noted):
- `app/Domain/CashSettlement/`
  - `Enums/SettlementStatus`, `Models/CashSettlement`, `Data/SettlementSubmission`
  - `Internal/SettlementNumberGenerator`
  - `Actions/{SubmitSettlement, CancelSettlement, DecideSettlement}`
  - `README.md` (changed)
- `app/Domain/CashLedger/`
  - new: `Actions/RecordSettlementOut`, `Services/CashSummary`
  - changed: `Internal/LedgerWriter` (`lockedBalance`)
- Changed: `app/Domain/Audit/Enums/AuditAction` (+4)
- HTTP (new): `Api/V1/CashSettlement/{SettlementController, SettlementResource}`,
  `Admin/CashSettlement/SettlementController`
- Routes (changed): `routes/api.php`, `routes/web.php`
- Migration: `2026_09_25_600000_create_cash_settlements_table` (also adds the ledger FK, the unique
  settlement-out index and the balance CHECK)
- Frontend:
  - new: `Pages/Settlements/{Index, Show, types}`
  - changed: `Layouts/AdminLayout.tsx`
- Tests:
  - new: `tests/Feature/Settlements/SettlementTest.php`
  - changed: `tests/Pest.php`

Android (changed): `data/remote/{Dto, ApiClient}.kt`, `data/ParkingRepository.kt`,
`data/local/Daos.kt`, `ui/{MainViewModel, MainActivity}.kt`, `test/{BackendIntegrationTest, QrisPolicyTest}.kt`,
`README.md`.

Docs:
- new: `docs/api/settlements.md`, `docs/decisions/0011-cash-settlement.md`, this report
- updated: `docs/api/README.md`, `docs/database/README.md`, `docs/architecture/overview.md`,
  `docs/decisions/README.md`, `docs/decisions/0007-financial-ledger-immutability.md`

## DATABASE CHANGES

| Object | Integrity in PostgreSQL |
|---|---|
| `cash_settlements`, `cash_settlement_number_seq`, `cash_settlements_guard()` | CHECK status, amount > 0, VERIFIED ⇔ verified amount, decided ⇔ decider/time, reject note, difference note, **four eyes**; one SUBMITTED per attendant; trigger: submission frozen, only SUBMITTED → VERIFIED/REJECTED/CANCELLED, decided rows frozen, no DELETE/TRUNCATE |
| `cash_ledger_entries` | FK `settlement_id`; unique SETTLEMENT_OUT per settlement; CHECK SETTLEMENT_OUT ⇒ `balance_after ≥ 0` |

The migration is reversible: rollback and re-migration were verified on the dev database.

## API

| Endpoint | Notes |
|---|---|
| `GET /api/v1/cash/summary` | total / today / open shift / pending |
| `POST /api/v1/settlements` | 201 / 200 replay / 409 `SYNC_CONFLICT` / 422 `SETTLEMENT_INVALID` |
| `GET /api/v1/settlements`, `/{uuid}` | own |
| `POST /api/v1/settlements/{uuid}/cancel` | 200 / 409 |

Admin web:
- `settlements`, `settlements/{id}`, `settlements/{id}/proof` (`settlements.view`);
- `PUT settlements/{id}/decision` (`settlements.verify`).

Contract: [docs/api/settlements.md](../api/settlements.md).

## TESTS

- Backend: `composer check` → Pint PASS (315 files), Larastan level 8 OK, **398 passed (2000
  assertions)**. Phase 7 adds 10 tests.

| SettlementTest case | Covers |
|---|---|
| Scenario D | Submission leaves money untouched; verification writes −5000 with the settlement FK; balance 6000 → 1000; summary total/today/shift; audit; `cash:verify-balances` still matches |
| Counted ≠ declared | Note required; counted amount booked, the rest outstanding; difference audited |
| Never more than held | Submission above the balance refused; verification above the balance refused; no ledger entry |
| Idempotency / one open | Replay; SYNC_CONFLICT; second open refused; cancel, then a new submission is allowed |
| Reject | Reason required; balance unchanged; no second decision |
| Roles / four eyes | Supervisor 403; Auditor sees but cannot decide; DB CHECK `decided_by <> submitted_by` |
| Frozen | Verified settlement: amount, status and delete refused (23001); settlement ledger entry append-only |
| Submission immutable, single settlement-out | Submitted amount frozen; a second SETTLEMENT_OUT refused (unique); a negative balance refused (CHECK) |
| Proof | Private storage; Finance can view, Executive Viewer cannot |
| Control Center | Totals, pending, outstanding per attendant; app summary shows pending; own list |

- **Mutation check:** booking the declared instead of the counted amount makes 2 tests fail.
- Android: `gradlew testDebugUnitTest assembleDebug` → BUILD SUCCESSFUL, **24 tests**. With the
  `PATI_E2E_*` variables, all three backend integration tests passed against the local backend
  through nginx (offline sync, QRIS, and the new settlement test: summary consistent → submit →
  replay returns the same number → cancel).

## STATIC ANALYSIS

- Pint PASS, Larastan level 8 OK, architecture tests pass (`CashSettlement\Internal` is private;
  the ledger is written only through CashLedger Actions), TypeScript build passes.

## SECURITY NOTES

- A deposit can only reduce the attendant's cash by what finance counted, never below zero,
  and only once per settlement. Both rules are enforced by the database as well.
- Separation of duties: the attendant submits and Finance decides, with four eyes in the DB.
  Supervisor and Super Admin cannot verify.
- Decided settlements cannot be edited or deleted, even through direct SQL.
- Proof photos are private and served only to `settlements.view`, with `nosniff`.

## ARCHITECTURE DECISIONS / ADR

- New **ADR-0011** (cash settlement): counted amount, per-attendant with an optional shift,
  one open submission, policy-neutral summaries. ADR-0007 notes updated.

## KNOWN LIMITATIONS

- The app does not send a proof photo yet (the API supports it).
- Adjustments for short or over deposits (with `adjustments.approve`) are not built. A short
  deposit simply stays outstanding.
- The settlement policy (per shift or per day) and the official approval flow (§62 #4, #11) are
  owner decisions. The model supports both.
- `STL-YYMMDD-########` is a dev format.

## DEVIATIONS FROM APPROVED PLAN

- The master doc lists `verified_at`/`verified_by`; they are named `decided_at`/`decided_by`
  because they also record rejections. The counted amount is stored in `verified_amount`
  (not in the master doc; it is needed for Scenario D).

## BLOCKERS

None. Owner items: settlement per shift or per day; whether finance alone verifies or a second
approval is needed for differences; the official settlement number format.

## NEXT RECOMMENDED PHASE

Phase 8 — Reconciliation (cross-checks of transactions, payments, ledger and settlements;
mismatch reports).
