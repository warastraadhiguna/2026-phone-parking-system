# QRIS Payment API

For the Android app, plus the provider webhook. Rules: master doc §15–§19, Scenario B;
[ADR-0006](../decisions/0006-payment-abstraction.md). Amounts are **integer rupiah**.

**Core rule:** the app never decides that a payment succeeded. A payment becomes `PAID` only
through verified provider confirmation: a signed webhook, or a backend-to-backend status call.
`PAID` never moves backwards.

## Flow (Scenario B)

1. `POST /api/v1/parking-transactions/qris`. The server creates the transaction
   (`WAITING_PAYMENT`) and the payment (`CREATED`), then asks the provider for a dynamic QR
   (payment `PENDING`).
2. The app shows `payment.qr_string` as a QR code and polls `GET /api/v1/payments/{payment_uuid}`
   every ~3 s.
3. The customer pays. The provider notifies the webhook, and the payment becomes `PAID` and the
   transaction `COMPLETED`.
4. The payment ends without money if:
   - the QR expires (`qris_expiry_minutes`, default 15) → `EXPIRED`;
   - the provider refuses → `FAILED`;
   - the attendant withdraws it → `CANCELLED`.

   In all three cases the transaction becomes `CANCELLED`. Retrying means a **new** transaction.

QRIS money is not cash held by the attendant, so there is no cash-ledger entry.

## `POST /api/v1/parking-transactions/qris`

Permission `mobile.payment.qris`. Same fields as a cash transaction
([transactions.md](transactions.md)), with `"payment_method": "QRIS"`. `offline_created` must be
false or absent.

```json
{ "transaction_uuid": "…", "shift_uuid": "…", "sync_sequence": 43, "vehicle_type": "MOTORCYCLE",
  "payment_method": "QRIS", "charged_amount": 2000, "tariff_id": 1,
  "transaction_time_device": "2026-09-25T08:15:00+07:00", "latitude": -6.7551, "longitude": 111.038, "gps_accuracy_m": 8 }
```

Response `201` (`200` when replayed):

```json
{
  "transaction": { "transaction_uuid": "…", "transaction_number": "TRX-260925-00000009", "status": "WAITING_PAYMENT", "payment_method": "QRIS", "charged_amount": 2000, "…": "…" },
  "payment": {
    "payment_uuid": "…", "status": "PENDING", "method": "QRIS", "provider": "MIDTRANS", "amount": 2000,
    "qr_string": "00020101021226…", "qr_image_url": "https://…/qr-code", "expires_at": "2026-09-25T08:30:00+07:00", "paid_at": null
  },
  "replayed": false
}
```

- `qr_string` / `qr_image_url` are only present while the payment is `PENDING`. Render `qr_string`
  on the device. `qr_image_url` is a fallback provided by the provider.
- If the provider refuses the charge, the response is still `201`, with payment `FAILED` and
  transaction `CANCELLED`.

| Case | Result |
|---|---|
| Same `transaction_uuid`, same payload (retry) | `200`, `replayed: true`, **same payment and QR**; no second charge |
| Same UUID, different payload / `sync_sequence` reused | `409 SYNC_CONFLICT` |
| `charged_amount` ≠ current server tariff | `409 TARIFF_CHANGED`, `details.expected_amount`, `details.tariff_id`. Nothing stored; refresh bootstrap |
| No OPEN shift (or not yet synced) | `409 SHIFT_NOT_ACTIVE` |
| Shift opened on another device | `403 DEVICE_NOT_ALLOWED` |
| No applicable tariff | `404 TARIFF_NOT_FOUND` |
| `offline_created: true` | `422` |
| Provider unreachable, outcome unknown | `503 SERVICE_UNAVAILABLE`, `details.retryable: true`. **Resend the same payload**: the server asks the provider whether the charge exists and continues |

## `GET /api/v1/payments/{payment_uuid}`

Own payments only (`404` otherwise). Returns `{ "payment": {…}, "transaction": {…} }`.

While the payment is open, the server may ask the provider for the current status, at most once
per `qris_status_check_seconds` (default 10). This covers lost webhooks. If the provider is
unreachable, the stored status is returned unchanged.

## `POST /api/v1/payments/{payment_uuid}/cancel`

The attendant withdraws an unpaid QR, for example when the customer pays cash instead. The server asks
the provider to cancel and applies **the provider's answer**. If the customer paid in the
meantime, the result is `PAID`, not `CANCELLED`. Returns the same shape as GET.

- `409 CONFLICT` when the payment is already final.
- `503` when the provider is unreachable (nothing changed).

## Webhook `POST /api/v1/payments/webhooks/{provider}`

Configured in the provider dashboard. For Midtrans: *Settings → Configuration → Payment
Notification URL* = `https://<domain>/api/v1/payments/webhooks/midtrans`. It needs no token and is
authenticated by the signature. Throttled per IP (`PAYMENT_WEBHOOK_PER_MINUTE`, default 300).

| Situation | Response | Effect |
|---|---|---|
| Only the active provider's URL exists | others `404` | |
| Signature invalid / fields missing | `403 FORBIDDEN` | Logged without payload; nothing stored |
| Verified, first time | `200 {"received": true, "outcome": "APPLIED" \| "NO_CHANGE" \| "AMOUNT_MISMATCH"}` | Event stored; status changed if allowed |
| Same notification again | `200 {"outcome": "DUPLICATE"}` | Nothing processed again |
| Verified, unknown order | `200 {"outcome": "UNKNOWN_PAYMENT"}` | Event stored for review |
| Unexpected error | `500` | Everything rolled back; the provider retries |

Midtrans signature: `SHA512(order_id + status_code + gross_amount + server_key)`. The server
compares `gross_amount` with the payment amount and **never marks PAID on a mismatch**. It
flags `PAYMENT_AMOUNT_MISMATCH` instead.

## Status rules

| Payment | Allowed next | Transaction effect |
|---|---|---|
| CREATED | PENDING, PAID, EXPIRED, FAILED, CANCELLED | |
| PENDING | PAID, EXPIRED, FAILED, CANCELLED | PAID → `COMPLETED`; others → `CANCELLED` |
| EXPIRED / FAILED / CANCELLED | PAID (late confirmation) | Transaction stays `CANCELLED`, flagged `LATE_PAYMENT` for finance |
| PAID | nothing | Void keeps the payment PAID; money returned = `MANUAL_REFUND` record |

The rules are enforced in `PaymentStateMachine` and again by the database trigger
`payments_guard()`. A provider refund notification never changes a PAID payment; finance
records it as a manual refund.

## Local development

`PAYMENT_GATEWAY=fake` (default). Simulate the customer:

```sh
docker compose exec app php artisan payments:fake-simulate <payment_uuid> settlement   # or expire | cancel | deny
```

The command sends a correctly signed notification through the real webhook processing.
`payments:check-pending` runs every minute. It asks the provider about open payments (expired
QR, unknown charge outcome, or no check for 5 minutes).

For the Midtrans sandbox, set `PAYMENT_GATEWAY=midtrans`, `MIDTRANS_ENVIRONMENT=sandbox` and
`MIDTRANS_SERVER_KEY=<sandbox key>` (never committed). The notification URL must be reachable
from the internet, for example through a tunnel. Without it, polling and `payments:check-pending`
still finish every payment.
