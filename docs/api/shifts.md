# Shift & Bootstrap API

For the Android app. Policy: [ADR-0008](../decisions/0008-offline-operation-policy.md).
All endpoints need `Authorization: Bearer <access_token>` of an attendant whose device is
**ACTIVE** (otherwise `403 DEVICE_NOT_ALLOWED`). All times are ISO-8601 **with an offset**
(`2026-09-25T07:00:00+07:00` or `…Z`).

## `GET /api/v1/bootstrap`

Call on app start and whenever online. Cache the result. **Do not start an offline shift with
a bootstrap older than `valid_until`** (`offline_config_max_age_hours`, default 72 h).

```json
{
  "generated_at": "2026-09-25T09:00:00+00:00",
  "valid_until": "2026-09-28T09:00:00+00:00",
  "business_date": "2026-09-25",
  "business_timezone": "Asia/Jakarta",
  "attendant": { "attendant_code": "JP-000001", "name": "…", "status": "ACTIVE" },
  "device": { "uuid": "…", "status": "ACTIVE", "status_label": "Aktif" },
  "assignment": { "id": 1, "location_id": 1, "effective_from": "2026-09-25", "effective_until": null },
  "location": { "id": 1, "location_code": "DEV-ALUN-01", "name": "…", "latitude": "-6.7550000", "longitude": "111.0380000",
                "geofence_radius_m": 50, "location_type": "ON_STREET", "status": "ACTIVE" },
  "tariffs": [
    { "tariff_id": 1, "vehicle_type": "MOTORCYCLE", "amount": 2000, "location_specific": false,
      "effective_from": "…", "effective_until": null }
  ],
  "settings": { "offline_transaction_warning_hours": 24, "max_open_shift_hours": 16, "offline_config_max_age_hours": 72,
                "max_clock_skew_minutes": 10, "gps_max_accuracy_m": 100 },
  "open_shift": null
}
```

- `tariffs` lists every approved tariff for the location valid at any time between now and
  `valid_until`, including upcoming changes. **Pricing rule on the device at time t:** use the
  `location_specific` row covering t; otherwise the location-type row covering t. Covering
  means `effective_from ≤ t < effective_until` (null = open).
- `assignment`/`location` are `null` without an assignment today. Then the app must not start
  a shift.

## Offline shift preconditions (ADR-0008)

The app may create a shift offline only if **all** hold:

1. The device was `ACTIVE` in the last bootstrap.
2. The attendant has logged in successfully on this device before.
3. A cached `assignment` exists.
4. The shift day is inside `effective_from … effective_until`.
5. Cached `tariffs`/`settings` exist.
6. The bootstrap is not past `valid_until`.

When back online, sync the shift **before** its transactions (Phase 5).

## `POST /api/v1/shifts/start`

```json
{
  "shift_uuid": "5a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d",
  "location_id": 1,
  "started_at_device": "2026-09-25T07:00:00+07:00",
  "latitude": -6.7551, "longitude": 111.038, "gps_accuracy_m": 8,
  "mock_location": false,
  "offline_created": false
}
```

The app generates `shift_uuid` once and reuses it for every retry.

| Result | HTTP | Body |
|---|---|---|
| Created | 201 | `{ "shift": {…}, "replayed": false }` |
| Same UUID, same payload (retry) | 200 | `{ "shift": {…}, "replayed": true }` |
| Same UUID, different payload | 409 | `SYNC_CONFLICT` |
| Another shift still open | 409 | `SHIFT_ALREADY_OPEN`, `details.open_shift_uuid` |
| Online, no assignment today / other location / location not active | 403 | `LOCATION_NOT_ALLOWED` (message says which) |
| Device not ACTIVE | 403 | `DEVICE_NOT_ALLOWED` |
| Invalid input (e.g. time without offset) | 422 | `VALIDATION_FAILED` |

**Offline shifts** (`offline_created: true`) are **accepted** even if a rule was broken, because
they already happened. The violation is recorded in `review_flags` instead. The assignment is
checked for the day the shift really started (device time, WIB).

### Review flags

| Flag | When |
|---|---|
| `NO_ASSIGNMENT` | Offline shift on a day without an assignment |
| `LOCATION_MISMATCH` | Offline shift at another location than assigned |
| `LOCATION_INACTIVE` | Offline shift at a non-active location |
| `DEVICE_NOT_APPROVED_AT_START` | Offline shift started before the device was approved |
| `OUTSIDE_GEOFENCE_START` / `OUTSIDE_GEOFENCE_END` | GPS significantly outside the geofence |
| `MOCK_LOCATION` | The device reported a mock location |
| `CLOCK_SKEW` | Online: device time differs from server by more than `max_clock_skew_minutes`; offline: device time in the future |
| `STALE_OFFLINE` | Offline shift synced more than `offline_transaction_warning_hours` after it started |
| `OVERDUE` | Still open after `max_open_shift_hours` (hourly job) |

### Geofence result

`INSIDE` (distance ≤ radius), `OUTSIDE` (distance > radius + GPS accuracy), `UNKNOWN`
(in between, no GPS, or accuracy worse than `gps_max_accuracy_m`). It is never a reason to reject.

## `POST /api/v1/shifts/end`

```json
{ "shift_uuid": "…", "ended_at_device": "2026-09-25T15:00:00+07:00", "latitude": -6.755, "longitude": 111.0381, "gps_accuracy_m": 10 }
```

| Result | HTTP |
|---|---|
| Closed now | 200 `replayed: false` |
| Same end again | 200 `replayed: true` |
| Already closed with a different end | 409 `SYNC_CONFLICT` |
| Force-closed by a supervisor | 200 `replayed: true`, `shift.status = FORCED_CLOSED` |
| End before start | 422 `VALIDATION_FAILED` (`details.field = ended_at_device`) |
| Unknown or someone else's shift | 404 `NOT_FOUND` |

## `GET /api/v1/shifts/active` · `GET /api/v1/shifts/{shift_uuid}`

Returns `{ "shift": {…} | null }`. Shift object:

```json
{
  "shift_uuid": "…", "status": "OPEN", "offline_created": false,
  "location": { "id": 1, "location_code": "DEV-ALUN-01", "name": "…" },
  "assignment_id": 1,
  "started_at_device": "…", "started_at_server": "…", "ended_at_device": null, "ended_at_server": null,
  "start_geofence_result": "INSIDE", "start_distance_m": 11, "end_geofence_result": null,
  "review_flags": [], "close_reason": null
}
```
