# API Conventions

Applies to every endpoint under `/api/v1` (mobile app, webhooks, machines).
The admin control center uses Inertia pages, not this API.

## Versioning

- All endpoints live under `/api/v1/` (master doc §41).
- A breaking change means a new version prefix. Adding optional fields is not breaking.
  Clients must ignore unknown fields.

## Response envelope

Every response, success or error, has exactly these four top-level keys:

```json
{
  "success": true,
  "data": { },
  "meta": { "request_id": "0199a1b2-..." },
  "error": null
}
```

```json
{
  "success": false,
  "data": null,
  "meta": { "request_id": "0199a1b2-..." },
  "error": {
    "code": "SHIFT_NOT_ACTIVE",
    "message": "Active shift is required.",
    "details": { }
  }
}
```

- `meta.request_id` is always present and equals the `X-Request-Id` response header. Quote it in
  bug reports ([observability](../architecture/observability.md)).
- `meta` may carry more fields later (for example pagination).
- `error.details` is optional and only appears when useful. Validation errors use
  `details.fields` (`{"plate": ["The plate field is required."]}`).
- Build responses only through `App\Support\Http\ApiResponse::success()` / `::error()`.

## Error codes

Clients must branch on `error.code`, never on `error.message` (master doc §43).
Source: `App\Support\Errors\ErrorCode`. Codes are never renamed or reused.

| Code | HTTP | Meaning |
|---|---|---|
| `BAD_REQUEST` | 400 (or the specific 4xx) | Request could not be processed (malformed, too large, …) |
| `VALIDATION_FAILED` | 422 | Input validation failed; see `details.fields` |
| `UNAUTHENTICATED` | 401 | No valid authentication |
| `FORBIDDEN` | 403 | Authenticated but not allowed |
| `NOT_FOUND` | 404 | Resource or route does not exist |
| `METHOD_NOT_ALLOWED` | 405 | Wrong HTTP method |
| `CONFLICT` | 409 | Conflicts with current state |
| `RATE_LIMITED` | 429 | Too many requests; honour `Retry-After` |
| `SERVICE_UNAVAILABLE` | 503 | Dependency unavailable (for example readiness failure) |
| `INTERNAL_ERROR` | 500 | Unexpected error; details only in server logs |
| `AUTH_INVALID` | 401 | Wrong credentials, or invalid/expired/reused refresh token |
| `ACCOUNT_DISABLED` | 403 | Correct credentials, but the account is inactive or suspended |
| `DEVICE_NOT_ALLOWED` | 403 | Device not approved / revoked / lost |
| `SHIFT_NOT_ACTIVE` | 409 | Operation needs an open shift |
| `SHIFT_ALREADY_OPEN` | 409 | Another shift of the attendant is still open (`details.open_shift_uuid`) |
| `LOCATION_NOT_ALLOWED` | 403 | Not assigned to this location |
| `TARIFF_NOT_FOUND` | 404 | No applicable tariff |
| `TRANSACTION_DUPLICATE` | 409 | Transaction already exists |
| `PAYMENT_FAILED` | 422 | Payment failed |
| `PAYMENT_EXPIRED` | 422 | QR expired |
| `SETTLEMENT_INVALID` | 422 | Settlement rejected by rules |
| `SYNC_CONFLICT` | 409 | Same UUID, different payload |
| `TARIFF_CHANGED` | 409 | QRIS amount differs from the current server tariff (`details.expected_amount`) |

Domain code signals an expected failure with `throw new ApiException(ErrorCode::X)`, or a
module exception extending `ApiException`. `ApiExceptionRenderer` maps framework exceptions
(validation, auth, 404, 405, throttling) automatically. Anything unexpected becomes
`INTERNAL_ERROR` with a generic message, **even when `APP_DEBUG=true`**.

## Idempotency (from Phase 4)

Creating transactions and shifts, and syncing them, is keyed on client-generated UUIDs
(`transaction_uuid`, `shift_uuid`). Repeating a request returns the existing record. The same
UUID with a different payload returns `SYNC_CONFLICT`. Details: [transactions.md](transactions.md), [sync.md](sync.md).

## Endpoints

| Method | Path | Auth | Description | Since |
|---|---|---|---|---|
| GET | `/api/v1/health/live` | none | Liveness ([semantics](../architecture/observability.md#3-health-endpoints)) | Phase 0 |
| GET | `/api/v1/health/ready` | none | Readiness: PostgreSQL + Redis | Phase 0 |
| POST | `/api/v1/auth/login` | none (throttled) | Attendant login, issues token pair ([auth.md](auth.md)) | Phase 1 |
| POST | `/api/v1/auth/refresh` | refresh token | Rotate tokens | Phase 1 |
| POST | `/api/v1/auth/logout` | Bearer | End the session | Phase 1 |
| GET | `/api/v1/auth/me` | Bearer | Current attendant, device, today's assignment | Phase 1–2 |
| GET | `/api/v1/bootstrap` | Bearer + active device | Offline cache: assignment, location, tariff schedule, settings, open shift ([shifts.md](shifts.md)) | Phase 3 |
| POST | `/api/v1/shifts/start` | Bearer + active device | Start shift (idempotent on `shift_uuid`) | Phase 3 |
| POST | `/api/v1/shifts/end` | Bearer + active device | End shift (idempotent) | Phase 3 |
| GET | `/api/v1/shifts/active` | Bearer + active device | Open shift or null | Phase 3 |
| GET | `/api/v1/shifts/{shift_uuid}` | Bearer + active device | One of the attendant's shifts | Phase 3 |
| POST | `/api/v1/parking-transactions` | Bearer + active device | Record a CASH transaction (idempotent) ([transactions.md](transactions.md)) | Phase 4 |
| GET | `/api/v1/parking-transactions` | Bearer + active device | Own transactions (paginated) | Phase 4 |
| GET | `/api/v1/parking-transactions/{uuid}` | Bearer + active device | One own transaction | Phase 4 |
| POST | `/api/v1/parking-transactions/{uuid}/void-request` | Bearer + active device | Ask a supervisor to void | Phase 4 |
| GET | `/api/v1/cash/balance` | Bearer + active device | Cash currently held (ledger) | Phase 4 |
| POST | `/api/v1/parking-transactions/qris` | Bearer + active device | Start a QRIS transaction; dynamic QR ([payments.md](payments.md)) | Phase 6 |
| GET | `/api/v1/payments/{uuid}` | Bearer + active device | Payment status (poll) | Phase 6 |
| POST | `/api/v1/payments/{uuid}/cancel` | Bearer + active device | Withdraw an unpaid QR | Phase 6 |
| POST | `/api/v1/payments/webhooks/{provider}` | provider signature (throttled) | Provider notifications | Phase 6 |
| GET | `/api/v1/cash/summary` | Bearer + active device | Expected / deposited / outstanding (total, today, shift) ([settlements.md](settlements.md)) | Phase 7 |
| POST | `/api/v1/settlements` | Bearer + active device | Declare a cash deposit (idempotent, optional proof) | Phase 7 |
| GET | `/api/v1/settlements`, `/{uuid}` | Bearer + active device | Own settlements | Phase 7 |
| POST | `/api/v1/settlements/{uuid}/cancel` | Bearer + active device | Withdraw an undecided deposit | Phase 7 |
| POST | `/api/v1/sync/transactions` | Bearer + active device | Batch sync of up to 50 queued transactions, per-item results ([sync.md](sync.md)) | Phase 5 |

Authenticated mobile endpoints require a Sanctum access token with ability `mobile` that
belongs to an `ACTIVE` attendant (`auth:sanctum` + `mobile.attendant` middleware). Admin web
sessions never authenticate API calls.
