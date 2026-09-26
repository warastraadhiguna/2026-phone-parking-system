# ADR-0006: Payment abstraction, webhooks and refunds

- Status: Accepted (includes owner decision Q3, 2026-09-25). Implementation: Phase 6.
- Date: 2026-09-25

## Context

QRIS Dynamic payments start with Midtrans. More providers may follow (master doc §15). The
status of a QRIS payment must come only from verified provider confirmation (§16). Financial
history must stay truthful, including when a paid transaction is voided.

## Decision

### Abstraction

- `App\Domain\Payment\Contracts\PaymentGatewayInterface` with operations along the lines of
  `createQrisCharge()`, `getStatus()`, `verifyAndParseWebhook()` and `cancel()`. The exact
  signatures are settled in Phase 6 against the then-current Midtrans documentation.
- Implementations live in `App\Domain\Payment\Internal\Gateways`:
  - `MidtransPaymentGateway`, which uses sandbox credentials until production is explicitly approved (§51 Rule 8, §54);
  - `FakePaymentGateway`, for local development and tests only.
- **The Fake gateway can never be active in production.** The binding refuses it when
  `app()->isProduction()`, and the application fails to boot if production config selects it.
  A test covers this.
- Only the Payment module knows provider credentials, signatures, endpoints and payload
  formats. ParkingTransaction talks to Payment Actions only. An architecture test enforces
  that `Midtrans` and `…\Payment\Internal\Gateways` are used only inside Payment.

### Webhook handling

1. Verify authenticity (signature) before trusting anything. Unverified requests are rejected
   and logged without the payload.
2. Record the event in `payment_webhook_events` with a unique provider event key. A duplicate
   is acknowledged without being processed again.
3. In one DB transaction: `lockForUpdate()` the payment, verify that the amount equals the
   transaction amount, and apply a **forward-only** status transition.
4. `PAID` never moves backwards because of a duplicate, delayed or out-of-order webhook.
5. On `PAID`: mark the parking transaction `COMPLETED` and write an audit record.
6. Android polls `GET /api/v1/payments/{id}`. It never decides that a payment succeeded.

### Void of a PAID QRIS transaction (Q3)

- Parking transaction: `COMPLETED → VOID_REQUESTED → VOIDED`.
- Payment: **stays `PAID`**. It is never rewritten to `CANCELLED`, `FAILED`, `EXPIRED` or unpaid.
- No automated Midtrans refund in the MVP. If finance returns money manually, a separate
  `payment_adjustments` record is created (type `MANUAL_REFUND`, amount, reason, actor,
  timestamp), linked to the payment and audited.
- Reconciliation reports **money received − money refunded**, so every rupiah is explained.

## Consequences

- Adding a provider means adding a gateway implementation, with no change to transaction code.
- The payment record reflects what really happened at the provider. Voids and refunds are
  explained by separate records.
- `payment_adjustments` and its reconciliation treatment are designed in Phase 6/8.

## Implementation notes (Phase 6)

- Midtrans Core API checked on 2026-09-25:
  - `POST /v2/charge` with `payment_type: qris`, `qris.acquirer` and `custom_expiry`;
  - `GET /v2/{order_id}/status`;
  - `POST /v2/{order_id}/cancel`, where 412 means the charge is final and its status is then read;
  - Basic auth `server_key:`;
  - notification signature `SHA512(order_id+status_code+gross_amount+server_key)`.
- `PaymentGatewayInterface` has `createQrisCharge`, `getStatus`, `cancel` and
  `verifyAndParseWebhook`. Adapters only talk to the provider; `Internal/PaymentStateMachine` is
  the only code that changes a payment status.
- The provider call runs **outside** DB transactions. When the outcome is unknown (timeout), the
  payment stays CREATED. The next attempt asks the provider: not found → charge again; pending
  without a stored QR → cancel; final → apply.
- Webhook table: the planned `payment_webhook_events` became `payment_provider_events`. It holds
  every verified answer (charge, status check, cancel, webhook), so the payment's full history is
  in one append-only table. Webhook duplicates are recognised by `event_key`.
- **Late confirmation:** a verified PAID after EXPIRED/FAILED/CANCELLED makes the payment PAID
  (the money arrived) and flags the already-cancelled transaction `LATE_PAYMENT` for finance.
- **Amount mismatch:** the payment is never marked PAID; the transaction is flagged
  `PAYMENT_AMOUNT_MISMATCH` and an audit record is written.
- **Lost notifications:** `payments:check-pending` (every minute) and app polling ask the
  provider. Nothing expires locally without the provider's answer.
- **Guardrails:** `PaymentGatewayResolver::assertSafeConfiguration()` runs at boot and on every
  resolve. It refuses the fake gateway in production, refuses Midtrans production unless
  `APP_ENV=production` and `MIDTRANS_PRODUCTION_APPROVED=true`, and refuses unknown names.
- **Manual refunds:** `RecordManualRefund` writes `payment_adjustments`. It needs permission
  `payments.refund_record`, which is held by Finance only, following Q3 ("finance returns money
  manually").
- Contract: [docs/api/payments.md](../api/payments.md).
