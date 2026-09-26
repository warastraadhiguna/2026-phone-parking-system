# Implementation Report — Phase 6

```text
PHASE:
Phase 6 — QRIS

STATUS:
DONE (sandbox-ready; tested against the fake provider and Midtrans-shaped HTTP fakes)
```

Date: 2026-09-26. Continued without waiting for approval, as instructed by the owner.

## IMPLEMENTED

- **PaymentGatewayInterface** (`createQrisCharge`, `getStatus`, `cancel`, `verifyAndParseWebhook`)
  with two implementations in `Payment\Internal\Gateways`:
  - `MidtransPaymentGateway`. Core API QRIS, checked against the Midtrans docs on 2026-09-25:
    `/v2/charge` with `custom_expiry`, `/v2/{order_id}/status` and `/v2/{order_id}/cancel`
    (412 → status read), Basic auth, and the SHA512 notification signature. It maps statuses
    conservatively: `capture/challenge` is not paid, `settlement + fraud deny` is failed, and a
    refund never counts as paid. Amounts are parsed only when they are whole rupiah.
  - `FakePaymentGateway`, for local development and tests. It is Midtrans-like (signed
    notifications, expiry, simulated outages and rejections).
- **Guardrails** (`PaymentGatewayResolver`, checked at boot and on every resolve):
  - the fake gateway is refused in production;
  - Midtrans production needs `APP_ENV=production` **and** `MIDTRANS_PRODUCTION_APPROVED=true`;
  - unknown gateway names are refused;
  - credentials come only from env, and the server key is never logged or stored.
- **Dynamic QR flow (Scenario B)**, `POST /api/v1/parking-transactions/qris`:
  - one DB transaction creates the transaction (`WAITING_PAYMENT`), the payment (`CREATED`) and
    the audit record;
  - the provider call happens **outside** the DB transaction; the payment becomes `PENDING`
    with the QR, reference and expiry;
  - idempotent on `transaction_uuid`: a retry returns the same payment and QR;
  - online only; the amount must equal the server tariff (`TARIFF_CHANGED`);
  - an unknown provider outcome returns 503 `retryable`. A retry asks the provider first
    (not found → charge again; pending without a stored QR → cancel; final → apply).
- **Status only from verified provider confirmation** (`PaymentStateMachine`, the single writer,
  with the payment row locked):
  - PAID only when the reported amount equals the payment amount (otherwise
    `PAYMENT_AMOUNT_MISMATCH` flag and audit);
  - forward-only; PAID is final;
  - a late PAID after EXPIRED/FAILED/CANCELLED makes the payment PAID and flags the cancelled
    transaction `LATE_PAYMENT`;
  - PAID → transaction `PAID → COMPLETED`; unpaid final → transaction `CANCELLED`.
- **Webhook** `POST /api/v1/payments/webhooks/{provider}`:
  - the signature is verified before anything else; an unauthentic request gets 403, is logged
    without its payload and stores nothing;
  - duplicates are recognised by `event_key` under the row lock;
  - verified notifications for unknown orders are recorded as `UNKNOWN_PAYMENT`;
  - throttled per IP; only the active provider's URL exists.
- **Polling and lost notifications:**
  - `GET /api/v1/payments/{uuid}` asks the provider at most once per `qris_status_check_seconds`;
  - `payments:check-pending` runs every minute;
  - nothing expires locally without the provider's answer.
- **Cancel:** `POST /api/v1/payments/{uuid}/cancel`. The provider's answer wins, so a payment
  made in the meantime becomes PAID, never CANCELLED.
- **Void of PAID QRIS (Q3):** the existing void flow applies. The payment stays PAID and no cash
  reversal is written. `payment_adjustments` records a **MANUAL_REFUND**:
  - PAID payments only;
  - the total can never exceed the payment amount (checked in the action **and** by a DB trigger);
  - append-only and audited.
- **Settings:** `qris_expiry_minutes` (15) and `qris_status_check_seconds` (10), editable at
  Pengaturan.
- **Control Center:**
  - **Pembayaran** list with the totals received, refunded manually and net;
  - payment detail with every provider answer (source, provider status, amount, outcome,
    status before → after) and the manual refund form (Finance);
  - the transaction detail shows its payment;
  - the void success message distinguishes CASH from QRIS.
- **Android:**
  - QRIS button next to each vehicle (online, synced shift only);
  - QR rendered on the device from `qr_string` (ZXing core, display only), status polled every
    3 s with a 120 s grace after expiry, a "Batalkan QR" button, and the provider's final status
    shown;
  - a lost request is resent unchanged (same UUID);
  - the shift view shows cash total and paid QRIS separately;
  - QRIS rows are stored locally but never queued;
  - Room v2 migration (`paymentMethod`, `paymentStatus`, `paymentUuid`) keeps existing data;
  - open QRIS rows are re-checked when the app is refreshed.
- `payments:fake-simulate {payment_uuid} {status}` sends a signed fake notification through the
  real webhook path (dev only; refused unless the fake gateway is active).

## FILES CHANGED

Backend (new unless noted):
- `app/Domain/Payment/`:
  - `Contracts/{PaymentGatewayInterface, PayableTransactions}`
  - `Data/{QrisChargeRequest, ChargeResult, ProviderStatus}`
  - `Enums/{PaymentStatus, PaymentProvider, GatewayStatus, ProviderEventSource, ProviderEventOutcome, AdjustmentType}`
  - `Exceptions/{GatewayUnavailable, InvalidWebhook, UnsafePaymentConfiguration}`
  - `Models/{Payment, PaymentProviderEvent, PaymentAdjustment}`
  - `Internal/Gateways/{MidtransPaymentGateway, FakePaymentGateway}`, `Internal/PaymentStateMachine`
  - `Services/PaymentGatewayResolver`
  - `Actions/{CreatePayment, ChargePayment, RefreshPaymentStatus, CancelPayment, HandleWebhook, RecordManualRefund}`
  - `Console/{CheckPendingPaymentsCommand, FakePaymentCommand}`
- `app/Domain/ParkingTransaction/`:
  - new: `Actions/CreateQrisTransaction`, `Data/{QrisTransactionData, QrisOutcome}`, `Services/TransactionPaymentOutcomes`
  - changed: `Enums/TransactionFlag` (+`LATE_PAYMENT`, `PAYMENT_AMOUNT_MISMATCH`)
- Changed:
  - `Audit/Enums/AuditAction` (+9 payment actions)
  - `SystemConfiguration/Enums/SettingKey` (+2)
  - `Support/Errors/ErrorCode` (+`TARIFF_CHANGED`)
  - `Identity/Enums/{Permission, Role}` (+`payments.refund_record` → FINANCE)
- HTTP:
  - new: `Api/V1/Payment/{PaymentController, WebhookController, StoreQrisTransactionRequest, PaymentResource}`, `Admin/Payment/PaymentController`
  - changed: `Admin/ParkingTransaction/TransactionController`
- Wiring (changed): `routes/api.php`, `routes/web.php`, `routes/console.php`, `bootstrap/app.php`,
  `app/Providers/AppServiceProvider.php` (bindings, boot guard, webhook throttle)
- Config: new `config/payment.php`; changed `.env.example` (payment keys, empty) and `phpunit.xml`
- Migrations: `2026_09_25_500000_create_payments_table` (also the QRIS CHECK on transactions),
  `…500100_create_payment_provider_events_table`, `…500200_create_payment_adjustments_table`
- Frontend:
  - new: `Pages/Payments/{Index, Show, types}`
  - changed: `Pages/Transactions/Show.tsx`, `Layouts/AdminLayout.tsx`
- Tests:
  - new: `tests/Feature/Payments/{QrisFlowTest, PaymentIntegrityTest, PaymentGatewayTest, PaymentAdminTest}`
  - changed: `tests/Pest.php`, `Operations/SettingsTest`, `Identity/RolePermissionMatrixTest`

Android:
- new: `domain/QrisPolicy.kt`, `ui/QrCode.kt`, `test/QrisPolicyTest.kt`, `app/schemas/…/2.json`
- changed:
  - build: `gradle/libs.versions.toml`, `app/build.gradle.kts` (zxing core 3.5.3)
  - data: `data/local/{Entities, AppDatabase, Daos}.kt`, `data/remote/{Dto, ApiClient}.kt`,
    `data/ParkingRepository.kt`
  - UI: `ui/{MainViewModel, MainActivity}.kt`
  - tests: `test/BackendIntegrationTest.kt` (+QRIS test, closes leftover shifts)
  - `README.md`

Docs:
- new: `docs/api/payments.md`, this report
- updated: `docs/api/README.md`, `docs/database/README.md`, `docs/architecture/overview.md`,
  `docs/architecture/roles-and-permissions.md`, `docs/decisions/0006-payment-abstraction.md`
  (implementation notes)

## DATABASE CHANGES

| Object | Integrity in PostgreSQL |
|---|---|
| `payments` + `payments_guard()` | Unique uuid / transaction / order id / (provider, reference); CHECK provider, method, amount > 0, status, PAID ⇔ paid_at, PENDING needs reference + expiry; trigger: identity and amount frozen, provider data set once, forward-only (PAID final), no DELETE/TRUNCATE |
| `payment_provider_events` | Append-only; unique `event_key` (webhooks only, CHECK); outcome/source CHECKs; payload object without signature |
| `payment_adjustments` + `payment_adjustments_limit()` | Append-only; MANUAL_REFUND only; amount > 0; reason required; trigger: PAID only, total ≤ amount (locks the payment row) |
| `parking_transactions_qris_check` | QRIS ⇒ `offline_created = false` and difference 0 |

All migrations are reversible (`down()` drops triggers, functions and the added constraint).

## API

| Endpoint | Notes |
|---|---|
| `POST /api/v1/parking-transactions/qris` | 201 / 200 replay / 409 `TARIFF_CHANGED` / 409 `SHIFT_NOT_ACTIVE` / 409 `SYNC_CONFLICT` / 503 retryable / 422 offline |
| `GET /api/v1/payments/{uuid}` | Own only; asks the provider at most once per interval |
| `POST /api/v1/payments/{uuid}/cancel` | Provider decides; 409 when final |
| `POST /api/v1/payments/webhooks/{provider}` | Signature; 403 / 200 (APPLIED, NO_CHANGE, AMOUNT_MISMATCH, UNKNOWN_PAYMENT, DUPLICATE) |

Admin web: `payments`, `payments/{id}` (`payments.view`), `POST payments/{id}/refunds`
(`payments.refund_record`). Contract: [docs/api/payments.md](../api/payments.md).

Verified through nginx (dev stack, fake gateway):
1. login → bootstrap → shift start;
2. QRIS returns WAITING_PAYMENT, PENDING and the QR;
3. poll returns PENDING;
4. `payments:fake-simulate … settlement` gives outcome APPLIED;
5. the DB shows payment PAID, transaction COMPLETED, 3 provider events (charge, status check,
   webhook);
6. `cash:verify-balances` reports everything matching (QRIS writes no cash entry).

## TESTS

- Backend: `docker compose exec app composer check` → Pint PASS (301 files), Larastan level 8
  `[OK] No errors`, **388 passed (1843 assertions)**. Phase 6 adds 45 tests.

| Suite | Covers |
|---|---|
| QrisFlowTest (17) | Scenario B end to end; idempotent retry (same QR, 1 charge) and SYNC_CONFLICT; duplicate notification processed once; forged signature / tampered amount / missing fields → 403, nothing stored; only the active provider's URL; **amount mismatch never PAID**; **PAID never backwards** (pending/expire/cancel/deny after settlement); expiry via provider status → CANCELLED; **no local expiry while the provider is down**; late PAID flagged; TARIFF_CHANGED / offline / no shift refused, nothing stored; provider rejection → FAILED/CANCELLED; unknown outcome 503 then recovery; cancel vs "paid meanwhile"; own payments only; unknown order recorded; **void of PAID QRIS keeps PAID, no cash reversal** |
| PaymentIntegrityTest (5) | Direct SQL: amount/reference frozen, PAID→PENDING/CANCELLED, DELETE, TRUNCATE all refused (23001); events append-only; QRIS offline/difference refused by CHECK; manual refund keeps PAID, never above amount, append-only; refund of unpaid refused; **DB trigger blocks over-refund even without the action** |
| PaymentGatewayTest (19) | Fake gateway refused in production (static check and container resolve); Midtrans production requires production + approval; unknown names refused; Midtrans charge request (Basic auth, body, `custom_expiry` in WIB) and response parsing; 406 refused vs 502/500/timeout unknown; status mapping ×9; cancel 412 → status; signature verification (valid, tampered amount, wrong key, missing fields) and the signature never kept; whole-rupiah parsing; no server key → safe failures |
| PaymentAdminTest (4) | List totals received/refunded/net; detail with provider answers; only Finance records refunds (Supervisor 403), a second refund above the remainder is refused; payment on the transaction page; Executive Viewer has no access |

- Android: `gradlew testDebugUnitTest assembleDebug` → BUILD SUCCESSFUL, **22 tests** (new
  `QrisPolicyTest` ×2). With the `PATI_E2E_*` variables, both backend integration tests passed
  against the local backend:
  - the offline sync test;
  - the new QRIS test: issue → retry returns the same payment → poll PENDING → withdraw →
    CANCELLED/CANCELLED.
- **Mutation check:** disabling the amount comparison in `PaymentStateMachine` makes "never marks
  PAID when the provider reports another amount" fail.

Issues found during the phase:
1. The new `tests/Feature/Payments` folder was not in the `RefreshDatabase` list, so the first
   run left rows in the test DB. It was added, and the next run refreshed the database.
2. Test-only problems:
   - the Sanctum guard remembered a previous request's user;
   - timezone comparison: Carbon keeps the +07:00 offset; the tests now compare in UTC.
3. The Android offline e2e test failed with `SHIFT_ALREADY_OPEN` after the manual smoke test left
   a shift open. The server behaved correctly. The tests now close a leftover shift first.

## STATIC ANALYSIS

- Pint PASS, Larastan level 8 OK (one model type fix: `transaction` is non-null).
- Architecture tests pass: `Payment\Internal\Gateways` and `Midtrans` are used only inside
  Payment; `Internal/` stays private.
- TypeScript strict build and the Android compile both pass.

## SECURITY NOTES

- A payment can become PAID only through a signature-verified notification or a
  backend-to-backend status call, and only for the exact amount.
- Nothing unauthenticated is stored. Stored payloads never contain the signature.
- `.env.example` has empty Midtrans keys; no credential is committed.
- The fake gateway cannot run in production: the app refuses to boot.
- Midtrans production cannot be switched on accidentally (it needs two independent settings).
- The webhook is throttled per IP. The provider IP allowlist is left for Phase 11 hardening.
- Payments, provider events and refunds are protected by triggers against edits and deletes,
  also through direct SQL.
- Every money-relevant change is audited in the same DB transaction.

## ARCHITECTURE DECISIONS / ADR

- ADR-0006 extended with implementation notes:
  - `payment_webhook_events` became `payment_provider_events` (every verified answer, not only
    webhooks);
  - late confirmation rule;
  - one payment per transaction: a retry is a new transaction, which answers master doc §19
    "desain final retry";
  - guardrails.
- **Role matrix change:** `payments.refund_record` was added and given to **FINANCE** only. This
  follows owner decision Q3 ("finance returns money manually … payment_adjustments record").
  **Please confirm** (CLAUDE.md rule 14).

## KNOWN LIMITATIONS

- **Not yet run against the real Midtrans sandbox:** no sandbox server key exists in this
  environment (none may be committed). The adapter is tested against HTTP fakes shaped like the
  Midtrans docs. First sandbox run:
  1. set `PAYMENT_GATEWAY=midtrans` and `MIDTRANS_SERVER_KEY`;
  2. configure the notification URL (a public tunnel is needed locally);
  3. create a QRIS transaction and pay it with the Midtrans simulator;
  4. confirm that `qr_string` is present (the docs sample shows only `actions`; the app falls
     back to an error message if neither can be rendered).
- The Android UI renders `qr_string` only. The `qr_image_url` fallback shows a message rather than
  downloading the image.
- A payment refunded at the provider (not manually) is only logged as a warning. Finance must
  book it as a manual refund. Reconciliation of provider settlement reports comes in Phase 8.
- No emulator UI test (same reason as Phase 5). The QRIS client path was verified end to end on
  the JVM against the real backend.

## DEVIATIONS FROM APPROVED PLAN

- The webhook table was renamed and broadened (see ADR notes).
- `REFUNDED` stays in the status list (master doc §17) but is never set (Q3).

## BLOCKERS

None for development. Owner items:
1. Confirm `payments.refund_record` → Finance.
2. A Midtrans **sandbox** server key for the integration run (to be placed in the server `.env`
   only), and the acquirer in the merchant contract (`gopay` default).
3. The QR lifetime (`qris_expiry_minutes`, default 15).

## NEXT RECOMMENDED PHASE

Phase 7 — Settlement (cash deposit submission and verification; SETTLEMENT_OUT ledger entries;
settlement FK on `cash_ledger_entries`).
