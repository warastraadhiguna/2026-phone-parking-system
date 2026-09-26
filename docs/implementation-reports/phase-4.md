# Implementation Report — Phase 4

```text
PHASE:
Phase 4 — Cash Transaction

STATUS:
DONE
```

Date: 2026-09-25. Continued without waiting for approval, as instructed by the owner.

## IMPLEMENTED

- **Cash parking transaction** `POST /api/v1/parking-transactions`:
  - idempotent on `transaction_uuid` and unique per `(device, sync_sequence)` (Scenario E: a
    retry after a lost response never duplicates);
  - a race on a unique index is resolved as a replay or `SYNC_CONFLICT`;
  - server-assigned `transaction_number` (`TRX-YYMMDD-########`).
- **Tariff snapshot** (master doc §9, ADR-0008 requirement 2):
  - `charged_tariff_amount` (what really happened, never replaced);
  - `server_expected_tariff_amount` + `tariff_id` from `TariffResolver`;
  - `tariff_difference_amount` (CHECK: expected − charged); a mismatch is flagged `TARIFF_MISMATCH`.
  - Online pricing uses server time, offline pricing uses device time.
- **Online vs offline:**
  - online requires an open shift on the same device and an applicable tariff;
  - offline records are accepted with review flags (`TARIFF_NOT_FOUND`, `OUTSIDE_SHIFT_WINDOW`,
    `DEVICE_MISMATCH`, `STALE_OFFLINE`, `CLOCK_SKEW`);
  - position flags (`OUTSIDE_GEOFENCE`, `MOCK_LOCATION`) apply to both;
  - the device-time policy is now shared by shifts and transactions (`DeviceTimePolicy`).
- **Cash ledger** (ADR-0007):
  - append-only `cash_ledger_entries` is the source of truth; a `PARKING_CASH_IN` is written in
    the same DB transaction as every cash transaction (§46 rule 9);
  - `attendant_cash_balances` is derived, maintained under a per-attendant row lock, and exposed
    as `GET /api/v1/cash/balance`;
  - `cash:verify-balances` (daily, alerts on mismatch) and `--fix` (rebuild from the ledger, audited).
- **Void** (§28, §46 rule 10):
  - the attendant requests in the app, or an operator in the Control Center: `COMPLETED → VOID_REQUESTED`;
  - a supervisor approves → `VOIDED` plus a ledger `REVERSAL` (the original transaction and entry
    stay), or rejects → `COMPLETED`;
  - four eyes (the requester cannot decide, also a DB CHECK); one pending request per transaction.
- **State machine in the database:** the trigger allows only the §47 transitions and freezes
  every identity, money, time and position column after insert. No DELETE or TRUNCATE.
- **History:** app list (per shift, paginated) and detail. Control Center **Transaksi** list with
  filters and revenue totals, and a detail page with the ledger lines, void history and void
  actions. Cash held is shown on the attendant page.
- **Audit:** `CREATE_TRANSACTION` (with the ledger entry id), `VOID_REQUESTED`, `VOID_APPROVED`,
  `VOID_REJECTED`, `CASH_BALANCES_REBUILT`.

## FILES CHANGED

Backend (new unless noted):
- `app/Domain/ParkingTransaction/`
  - `Enums/{PaymentMethod, TransactionStatus, TransactionFlag, VoidRequestStatus, VoidChannel}`
  - `Models/{ParkingTransaction, VoidRequest}`
  - `Data/{CashTransactionData, TransactionOutcome}`
  - `Internal/TransactionNumberGenerator`
  - `Actions/{RecordCashTransaction, RequestVoid, DecideVoid}`
- `app/Domain/CashLedger/`
  - `Enums/LedgerEntryType`, `Models/{CashLedgerEntry, AttendantCashBalance}`
  - `Internal/LedgerWriter`, `Actions/{RecordCashIn, ReverseLedgerEntry}`
  - `Services/CashBalances`, `Console/VerifyCashBalancesCommand`
- `app/Domain/SystemConfiguration/Services/DeviceTimePolicy.php`; changed: `Shift/Actions/StartShift.php` (uses it)
- Changed: `app/Domain/Audit/Enums/AuditAction.php` (+5)
- HTTP:
  - new: `Api/V1/DeviceRequest.php` (moved from `Shift/ShiftRequest.php`, now shared),
    `Api/V1/ParkingTransaction/{TransactionController, StoreTransactionRequest, TransactionResource}`,
    `Admin/ParkingTransaction/TransactionController`
  - changed: `Admin/ParkingAttendant/AttendantController` (cash balance)
- Wiring (changed): `routes/api.php`, `routes/web.php`, `routes/console.php` (daily verification), `bootstrap/app.php`
- Migrations: `2026_09_25_400000_create_parking_transactions_table`, `…400100_create_cash_ledger_tables`,
  `…400200_create_void_requests_table`
- Frontend:
  - new: `Pages/Transactions/{Index, Show, types}`
  - changed: `Pages/Attendants/Show.tsx`, `Layouts/AdminLayout.tsx`
- Tests:
  - new: `tests/Feature/Transactions/{CashTransactionTest, VoidTest, CashLedgerTest, TransactionAdminTest}`,
    `tests/Support/TransactionHelpers.php`
  - changed: `tests/Pest.php`, `tests/Feature/MasterData/TariffTest.php` (truncate now needs CASCADE)

Docs:
- new: `docs/api/transactions.md`, this report
- updated: `docs/api/README.md`, `docs/database/README.md`, `docs/architecture/overview.md`,
  `docs/decisions/0007-financial-ledger-immutability.md` (implementation notes)

## DATABASE CHANGES

| Object | Integrity in PostgreSQL |
|---|---|
| `parking_transactions` + `parking_transaction_number_seq` + `parking_transactions_guard()` | Unique uuid / number / (device, sync_sequence); CHECK vehicle, method, status, positive amounts, difference formula, cash-status subset, plate, flags; trigger: frozen columns, §47 transitions, no DELETE/TRUNCATE |
| `cash_ledger_entries` | Append-only triggers; CHECK type, amount ≠ 0, per-type sign/reference; unique cash-in per transaction; unique reversal per entry |
| `attendant_cash_balances` | Derived; PK attendant |
| `void_requests` | CHECK status/channel/decision consistency; four eyes; one pending per transaction |

All three migrations are reversible; rollback and re-migration were verified.

## API

| Endpoint | Notes |
|---|---|
| `POST /api/v1/parking-transactions` | CASH; 201 / 200 replay / 409 `SYNC_CONFLICT` / 409 `SHIFT_NOT_ACTIVE` / 403 `DEVICE_NOT_ALLOWED` / 404 `TARIFF_NOT_FOUND` (online) / 422 |
| `GET /api/v1/parking-transactions`, `/{uuid}` | Own history |
| `POST /api/v1/parking-transactions/{uuid}/void-request` | 201 PENDING / 409 |
| `GET /api/v1/cash/balance` | Derived balance from the ledger |

Admin web: `transactions`, `transactions/{id}`, `POST transactions/{id}/void-request`
(`transactions.void_request`), `PUT void-requests/{id}` (`transactions.void_approve`).
Contract: [docs/api/transactions.md](../api/transactions.md).

Verified through nginx with the seeded attendant:
1. shift start returns 201;
2. a cash transaction returns `TRX-260925-00000001`, COMPLETED, balance Rp2.000;
3. the same request again returns `replayed: true` with the balance still Rp2.000;
4. `cash:verify-balances` reports everything matching.

## TESTS

- Command: `docker compose exec app composer check`
- Result: **339 passed (1480+ assertions)**, about 170 s. Phase 4 adds 38 tests.

| Suite | Covers |
|---|---|
| CashTransactionTest (23) | Scenario A (snapshot, number format, cash-in, balance, audit); **Scenario E** idempotent retry; conflicts on UUID and sync_sequence; **tariff changed → charged kept, expected + difference + flag, ledger uses charged**; offline priced at device time; online `TARIFF_NOT_FOUND` vs offline flagged; shift closed/unknown vs offline `OUTSIDE_SHIFT_WINDOW`; geofence/mock flags + plate normalisation; QRIS refused here; balance accumulation (2000 → 4000 → 5000); own list/show, others 404; **DB: frozen columns ×3, no delete/truncate, illegal transition (23001); allowed §47 path; ledger append-only, single cash-in (23505), amount ≠ 0 (23514)**; shift link |
| VoidTest (7) | App request leaves money untouched; approval → VOIDED + exact REVERSAL + balance 0 + audit; rejection → COMPLETED; operator request from the Control Center but no approval; invalid steps (double request, double decision, re-void); **four eyes in the DB**; Finance/Super Admin cannot request |
| CashLedgerTest (5) | Verification passes; a tampered derived balance is detected and rebuilt from the ledger (ledger untouched, audit); missing derived row rebuilt; the writer refuses to run outside a transaction; reversal once, never a reversal of a reversal |
| TransactionAdminTest (3) | List with revenue totals and flagged filter; detail with ledger; role restriction |

**Mutation check:** crediting the ledger with the server tariff instead of the charged amount
makes 2 CashTransactionTest cases fail.

Issues found during the phase:
1. The `TRUNCATE tariffs` test now fails earlier, because `parking_transactions` references
   tariffs. That is still a refusal; the test uses `CASCADE` to reach the trigger.
2. One freeze-test case used `now()` and depended on timing. It now uses a fixed timestamp.

## STATIC ANALYSIS

- **Pint:** PASS (252 files).
- **Larastan level 8:** `[OK] No errors` (one list-type fix).
- **Architecture tests:** pass. `CashLedger\Internal\LedgerWriter` and
  `ParkingTransaction\Internal\TransactionNumberGenerator` are private to their modules.
- **TypeScript** strict build: passes.

## SECURITY NOTES

- Money cannot be edited after the fact, even with direct SQL through the application role.
  Triggers freeze transactions and make the ledger append-only; corrections are reversals and
  adjustments with audit.
- Voids need two different people (requester ≠ supervisor), enforced in the database.
- Every write that moves money (cash-in, reversal) happens in the same DB transaction as the
  business change and its audit record.
- Attendants only see and void their own transactions (404 for others).
- The nightly balance verification detects any drift or tampering of the derived balance table.

## ARCHITECTURE DECISIONS / ADR

- ADR-0007 extended with implementation notes: the single writer and lock, DB guarantees,
  verification/rebuild, and the transaction trigger. No new ADR was needed.

## KNOWN LIMITATIONS

- A batch sync endpoint for many offline transactions at once comes in Phase 5. Today each
  offline transaction is posted individually with `offline_created: true`, with the same
  idempotency.
- A cash summary per shift or day and settlements come in Phase 7. `ADJUSTMENT` entries (for
  example a manual correction by finance) have no UI yet.
- QRIS transactions come in Phase 6. The table, status machine and trigger already support them.
- The transaction number format is a dev default (owner item §62 #8).

## DEVIATIONS FROM APPROVED PLAN

- **Void workflow implemented in Phase 4** (the master doc lists it as an MVP rule, not in a
  specific phase), because its cash reversal belongs to the ledger work.
- `ShiftRequest` became the shared `Api/V1/DeviceRequest` base (shifts and transactions).

## BLOCKERS

None. Owner confirmations:
1. Official transaction number format (§62 #8); default `TRX-YYMMDD-########`.
2. Is the vehicle plate mandatory (§62 #6)? It is optional now.
3. Void requesters: attendant (app) and operator. Supervisor decides. Confirm (§62 #5).

## NEXT RECOMMENDED PHASE

Phase 5 — Android Offline Sync (Room, sync queue, retry, duplicate-safe batch sync).
