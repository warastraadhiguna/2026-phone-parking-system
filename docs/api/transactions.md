# Parking Transaction & Cash API

For the Android app. Requires `Authorization: Bearer <access_token>` of an attendant whose
device is **ACTIVE**. Times are ISO-8601 with offset. Amounts are **integer rupiah**.
Rules: master doc §10–§12, §21, §28, §46–§47; [ADR-0007](../decisions/0007-financial-ledger-immutability.md),
[ADR-0008](../decisions/0008-offline-operation-policy.md).

## `POST /api/v1/parking-transactions` (CASH)

```json
{
  "transaction_uuid": "…",               // generated once on the device, reused for retries
  "shift_uuid": "…",                     // the shift must already be synced
  "sync_sequence": 42,                   // per-device counter, strictly unique per device
  "vehicle_type": "MOTORCYCLE",          // MOTORCYCLE | CAR | OTHER
  "vehicle_plate": "K 1234 AB",          // optional, normalised to K1234AB
  "payment_method": "CASH",
  "charged_amount": 2000,                // what the customer really paid
  "tariff_id": 1,                        // the tariff the device used (from bootstrap), optional
  "transaction_time_device": "2026-09-25T08:15:00+07:00",
  "latitude": -6.7551, "longitude": 111.038, "gps_accuracy_m": 8, "mock_location": false,
  "offline_created": false
}
```

Response (`201` created, `200` replay):

```json
{
  "transaction": {
    "transaction_uuid": "…", "transaction_number": "TRX-260925-00000001", "shift_uuid": "…",
    "status": "COMPLETED", "payment_method": "CASH", "vehicle_type": "MOTORCYCLE", "vehicle_plate": null,
    "charged_amount": 2000, "expected_amount": 2000, "difference_amount": 0, "tariff_id": 1,
    "sync_sequence": 42, "transaction_time_device": "…", "transaction_time_server": "…",
    "geofence_result": "INSIDE", "offline_created": false, "review_flags": []
  },
  "replayed": false,
  "cash_balance": 2000
}
```

| Case | Result |
|---|---|
| Same `transaction_uuid`, same payload (retry after lost response) | `200`, `replayed: true`. No second transaction or ledger entry. |
| Same `transaction_uuid`, different payload, **or** `sync_sequence` already used by another transaction | `409 SYNC_CONFLICT` |
| Shift unknown (not yet synced), or online and the shift is not OPEN | `409 SHIFT_NOT_ACTIVE` |
| Online and the shift was opened on another device | `403 DEVICE_NOT_ALLOWED` |
| Online and no tariff applies | `404 TARIFF_NOT_FOUND` (nothing recorded) |
| `payment_method` other than CASH | `422` (QRIS uses the payment flow, Phase 6) |

### Tariff snapshot (ADR-0008)

- `charged_amount` is **what really happened** and is never replaced. It is also the amount added
  to the attendant's cash ledger.
- The server stores its own applicable tariff (`tariff_id`, `expected_amount`) and
  `difference_amount = expected − charged`. A difference adds `TARIFF_MISMATCH`.
- Pricing time: **online** = server time; **offline** = `transaction_time_device`.

### Offline transactions (`offline_created: true`)

Accepted when the shift exists, even if a rule was broken. Signals are recorded in `review_flags`:

| Flag | Meaning |
|---|---|
| `TARIFF_MISMATCH` | Charged ≠ server tariff at that time |
| `TARIFF_NOT_FOUND` | No server tariff at that time (`expected_amount` null) |
| `OUTSIDE_SHIFT_WINDOW` | Time before the shift start or after its end |
| `DEVICE_MISMATCH` | Recorded on another device than the shift |
| `OUTSIDE_GEOFENCE`, `MOCK_LOCATION` | Position signals (also online) |
| `CLOCK_SKEW`, `STALE_OFFLINE` | Timing signals (same rules as shifts) |

## `GET /api/v1/parking-transactions?shift_uuid=…&per_page=50`

The attendant's own transactions, newest first. `data` is a list; `meta` holds
`page`, `per_page`, `total`, `last_page`.

## `GET /api/v1/parking-transactions/{transaction_uuid}`

One own transaction (`404` otherwise).

## `POST /api/v1/parking-transactions/{transaction_uuid}/void-request`

`{ "reason": "Salah pilih kendaraan" }` → `201 { "void_request_id": 7, "status": "PENDING" }`.
The transaction becomes `VOID_REQUESTED`. Money is unchanged until a supervisor decides:
- **Approve:** the transaction becomes `VOIDED`, and a ledger `REVERSAL` removes the cash from the
  attendant's balance.
- **Reject:** the transaction returns to `COMPLETED`.

Only `COMPLETED` transactions can be voided (`409` otherwise).

## `GET /api/v1/cash/balance`

`{ "cash_balance": 2000, "as_of": "…" }`: cash the attendant currently holds according to the
ledger (cash-ins − reversals − verified settlements from Phase 7).
