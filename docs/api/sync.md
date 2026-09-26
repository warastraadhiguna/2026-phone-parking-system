# Offline Sync API

For the Android app after working offline. Requires `Authorization: Bearer <access_token>` of an
attendant whose device is **ACTIVE**. Rules: master doc §21, §41; [ADR-0008](../decisions/0008-offline-operation-policy.md).

## Order the device must follow

1. **Shift starts** — `POST /api/v1/shifts/start` per shift ([shifts.md](shifts.md)), `offline_created: true`.
2. **Transactions** — `POST /api/v1/sync/transactions` in batches of at most 50, only for shifts
   whose start is already synced.
3. **Shift ends** — `POST /api/v1/shifts/end`, only once every transaction of that shift is synced
   (or permanently rejected).

Payloads are resent **unchanged** on every retry. The server is idempotent on
`shift_uuid` / `transaction_uuid` plus a payload fingerprint, so a resend after a lost response
never duplicates anything (Scenario E).

## `POST /api/v1/sync/transactions`

```json
{
  "transactions": [
    { "transaction_uuid": "…", "shift_uuid": "…", "sync_sequence": 42, "vehicle_type": "MOTORCYCLE",
      "payment_method": "CASH", "charged_amount": 2000, "tariff_id": 1,
      "transaction_time_device": "2026-09-25T08:15:00+07:00",
      "latitude": -6.7551, "longitude": 111.038, "gps_accuracy_m": 8, "mock_location": false,
      "offline_created": true }
  ]
}
```

Each item has exactly the fields of `POST /api/v1/parking-transactions` ([transactions.md](transactions.md)).
`transactions` must hold 1–50 items; otherwise the whole request is `422 VALIDATION_FAILED`.

Response `200` (even when some items are rejected):

```json
{
  "results": [
    { "index": 0, "transaction_uuid": "…", "result": "CREATED", "transaction": { … }, "error": null },
    { "index": 1, "transaction_uuid": "…", "result": "EXISTING", "transaction": { … }, "error": null },
    { "index": 2, "transaction_uuid": "…", "result": "REJECTED", "transaction": null,
      "error": { "code": "SHIFT_NOT_ACTIVE", "message": "…", "retryable": true } }
  ],
  "summary": { "CREATED": 1, "EXISTING": 1, "REJECTED": 1 },
  "cash_balance": 4000
}
```

- Items are processed **in the order sent**, each in its own DB transaction: one bad item never
  blocks or rolls back the others.
- `index` matches the position in the request.
- `CREATED` = stored now. `EXISTING` = already stored with the same data (a retry): treat as synced.
- `REJECTED` = not stored. `error.code` is a stable [error code](README.md#error-codes).
  - `retryable: true` (`SHIFT_NOT_ACTIVE` — the shift is not synced yet, `INTERNAL_ERROR`,
    `SERVICE_UNAVAILABLE`): keep the item and resend it later, unchanged.
  - `retryable: false` (`VALIDATION_FAILED`, `SYNC_CONFLICT`, …): resending cannot help. The app
    marks the item FAILED and keeps it on the device for a supervisor. It is never deleted.
- `cash_balance` is the attendant's cash held after the batch, from the ledger.
- Offline anomalies are **not** rejections: they are stored with `review_flags` exactly as in the
  single-transaction endpoint (tariff mismatch, stale offline, clock skew, geofence, …).

## Android implementation

`id.pati.parking.sync.SyncEngine` (see [android/README.md](../../android/README.md)):

- Room tables `sync_queue` (PENDING → SYNCING → SYNCED / FAILED), `local_shifts`, `local_transactions`.
- `SyncWorker` (WorkManager, unique work) runs when the network is available, with exponential
  backoff from 30 s. Items left in SYNCING by a killed process go back to PENDING at the next run.
- A network error stops the run and leaves everything PENDING. `401` triggers one token refresh;
  if that fails, the attendant logs in again and nothing queued is lost.
