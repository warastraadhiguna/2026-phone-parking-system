# ADR-0008: Offline operation — shifts, cash transactions, tariff mismatch

- Status: Accepted (owner decisions Q2, Q5 and additional requirement 2, 2026-09-25).
  Implementation: Phases 3–5.
- Date: 2026-09-25

## Context

Attendants must keep recording CASH parking when connectivity is poor (master doc §3.5,
§20–§23). QRIS stays online-only. Offline data must never be lost or duplicated, and must
never silently alter what happened in the field.

## Decision

### Offline shift start (Q2)

A device may create a shift offline, generating `shift_uuid` locally, only when **all** of
these hold:

1. The device has already been approved by the server (`ACTIVE`).
2. The attendant has previously authenticated successfully on this device.
3. Assignment data is cached locally.
4. The cached assignment is still valid for its validity period.
5. The tariff/configuration bootstrap exists locally.
6. The local configuration is not older than the allowed offline age (a system setting).

Sync order on reconnect: offline shift → server validates → server ACK → only then that
shift's transactions sync. Shift creation is **idempotent on `shift_uuid`**. A retry after a
lost response returns the existing shift. The same UUID with a different payload returns
`SYNC_CONFLICT`. Server-side rules (one open shift per attendant, device active) are checked
at sync. A violation is flagged for review; the shift is not silently dropped.

### Offline cash transactions

- CASH only. Each transaction carries `transaction_uuid`, `device_uuid`, `sync_sequence` and
  device timestamps. Server creation is idempotent (unique constraints).
- Local data is kept until the server ACK arrives. Retries use exponential backoff through WorkManager.

### Configurable thresholds (Q5)

| Setting | Default (dev/policy, not regulation) | Behaviour |
|---|---|---|
| `offline_transaction_warning_hours` | 24 | An older offline transaction is **still accepted** when technically possible. The financial record is preserved and an anomaly flag is raised for review. It is never silently discarded. |
| `max_open_shift_hours` | 16 | A longer-open shift is flagged. Authorized admin/supervisor actions (for example force close → `FORCED_CLOSED`) follow the later business workflow. |

These values are stored as **system settings** (SystemConfiguration module), never as code constants.

### Stale offline tariff (additional requirement 2)

The recorded amount is what the customer was really charged. The server never replaces it.

```text
charged_tariff_amount          = 2000   (what really happened)
server_expected_tariff_amount  = 3000   (tariff valid at transaction time on the server)
tariff_difference_amount       = 1000
anomaly                        = TARIFF_MISMATCH  → review / reconciliation
```

## Consequences

- No lost field revenue records. Discrepancies become visible review items instead of silent
  corrections.
- Sync endpoints need per-item results (`CREATED`, `EXISTING`, `REJECTED(code)`) and careful
  ordering (shift before transactions).
- The device must cache assignment, tariff and config with timestamps, to prove offline
  preconditions 3–6.

## Implementation notes (Phase 3)

- Settings live in `system_settings` (SystemConfiguration module; keys, defaults and bounds are
  defined in `SettingKey`) and are editable at **Pengaturan** (`system.configure`, audited):
  `offline_transaction_warning_hours` 24, `max_open_shift_hours` 16,
  `offline_config_max_age_hours` 72 (precondition 6), `max_clock_skew_minutes` 10,
  `gps_max_accuracy_m` 100.
- `GET /api/v1/bootstrap` supplies preconditions 3–6. Its `valid_until` is the offline age limit.
- Shift sync is idempotent on `shift_uuid` with a payload fingerprint. Offline violations become
  `review_flags` on the shift: `NO_ASSIGNMENT`, `LOCATION_MISMATCH`, `LOCATION_INACTIVE`,
  `DEVICE_NOT_APPROVED_AT_START`, `STALE_OFFLINE`, `CLOCK_SKEW`, `OUTSIDE_GEOFENCE_*`,
  `MOCK_LOCATION`, and `OVERDUE` from the hourly job. One OPEN shift per attendant is the only hard
  rule, enforced by a partial unique index.
- Supervisors force-close shifts (`FORCED_CLOSED`, reason required, audited). The device's next
  `end` call receives the forced status instead of an error.
- Contract: [docs/api/shifts.md](../api/shifts.md).
## Implementation notes (Phase 5)

- Batch endpoint `POST /api/v1/sync/transactions` (max 50) returns a per-item result
  `CREATED` / `EXISTING` / `REJECTED` with a stable code and `retryable`. Each item runs in its own
  DB transaction. Contract: [docs/api/sync.md](../api/sync.md).
- The Android app is offline-first. Every write goes to Room plus `sync_queue` in one Room
  transaction. `SyncEngine` enforces the order shift start → transactions → shift end. Rejected
  items are kept as FAILED on the device and never deleted.
- Preconditions 3–6 are checked on the device from the cached bootstrap (`OfflineShiftRules`).
  The server re-checks everything and flags violations; it never rejects a real offline record
  because of them.
