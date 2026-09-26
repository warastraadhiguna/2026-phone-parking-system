# Implementation Report — Phase 3

```text
PHASE:
Phase 3 — Shift

STATUS:
DONE
```

Date: 2026-09-25. Continued without waiting for approval, as instructed by the owner.

## IMPLEMENTED

- **Policy settings** (SystemConfiguration module, first consumer):
  - `system_settings` with keys, defaults and bounds defined in code;
  - `offline_transaction_warning_hours` 24 and `max_open_shift_hours` 16 (owner Q5),
    `offline_config_max_age_hours` 72, `max_clock_skew_minutes` 10, `gps_max_accuracy_m` 100;
  - editable at **Pengaturan** (`system.configure`), audited as `SETTING_CHANGED`, never hardcoded;
  - read per request from PostgreSQL through a request-scoped `Settings` service.
- **Geofence** (§24): Haversine distance.
  - `INSIDE` when the distance ≤ radius; `OUTSIDE` when the distance > radius + GPS accuracy
    ("significant"); otherwise `UNKNOWN`.
  - A fix without a position, or with accuracy worse than the configured maximum, gives `UNKNOWN`.
  - The result is a signal only, never a reason to reject.
- **Bootstrap** `GET /api/v1/bootstrap`: today's assignment, location with its geofence, the
  tariff schedule (current and upcoming, within `valid_until`), settings, and the open shift.
  This supplies offline preconditions 3–6 of ADR-0008.
- **Shift start** `POST /api/v1/shifts/start`:
  - idempotent on the device-generated `shift_uuid`: a retry returns 200 `replayed`, and
    different data returns `SYNC_CONFLICT`;
  - online starts are validated strictly: assignment today, assigned location, location active;
  - **offline shifts are accepted** with `review_flags` (no assignment, location mismatch,
    inactive location, device not yet approved at start, stale, clock skew);
  - GPS, accuracy, mock flag, geofence result and distance are recorded;
  - one OPEN shift per attendant (application check plus partial unique index; a concurrent
    race maps to `SHIFT_ALREADY_OPEN`).
- **Shift end** `POST /api/v1/shifts/end`:
  - idempotent; a conflicting second end returns 409;
  - an end before the start returns 422;
  - a force-closed shift is reported as such rather than as an error;
  - another attendant's shift returns 404.
- `GET /api/v1/shifts/active`, `GET /api/v1/shifts/{uuid}`.
- **Supervision:**
  - admin **Shift** list with filters (status, location, date, flagged only) and a detail page
    with GPS and flags;
  - **force close** (`shifts.force_close`, Supervisor only), reason required, audited;
  - hourly `shifts:flag-overdue` adds `OVERDUE` once, using the configurable threshold.
- **Audit:** `START_SHIFT`, `END_SHIFT` (§27), `SHIFT_FORCE_CLOSED`, `SHIFT_FLAGGED`,
  `SETTING_CHANGED`, all with device UUID and request ID where applicable.
- **Admin UI:** Shift (list/detail), Pengaturan. Menu entries are permission-aware.

## FILES CHANGED

Backend (new unless noted):
- `app/Domain/SystemConfiguration/{Enums/SettingKey, Models/SystemSetting, Services/Settings, Actions/UpdateSetting}`
- `app/Domain/Shift/`
  - `Enums/ShiftStatus`, `Enums/ShiftFlag`, `Models/Shift`
  - `Data/{ShiftStartData, ShiftEndData, ShiftOutcome}`
  - `Actions/{StartShift, EndShift, ForceCloseShift, FlagOverdueShifts}`
  - `Services/ShiftLookup`, `Console/FlagOverdueShiftsCommand`
- `app/Domain/ParkingLocation/{Enums/GeofenceResult, Services/Geofence, Data/GeofenceCheck}`
- `app/Domain/Tariff/Services/TariffResolver.php` (changed: `scheduleFor()`)
- `app/Support/Geo/GpsFix.php`, `app/Support/Idempotency/PayloadHash.php`
- `app/Support/Errors/ErrorCode.php` (changed: `SHIFT_ALREADY_OPEN`), `app/Domain/Audit/Enums/AuditAction.php` (changed: +5)
- HTTP:
  - new: `Api/V1/Shift/{ShiftController, ShiftRequest, StartShiftRequest, EndShiftRequest, ShiftResource}`,
    `Api/V1/System/BootstrapController`, `Admin/Shift/ShiftController`,
    `Admin/SystemConfiguration/SettingsController`
  - changed: `Middleware/EnsureActiveDevice` (exposes the verified device)
- Wiring (changed): `routes/api.php`, `routes/web.php`, `routes/console.php` (hourly overdue),
  `bootstrap/app.php`, `AppServiceProvider` (scoped `Settings`)
- Migrations: `2026_09_25_300000_create_system_settings_table`, `…300100_create_shifts_table`
- Frontend:
  - new: `Pages/Shifts/{Index, Show, types}`, `Pages/Settings/Index`
  - changed: `Layouts/AdminLayout.tsx`
- Tests:
  - new: `tests/Feature/Operations/{ShiftApiTest, BootstrapTest, ShiftAdminTest, SettingsTest}`, `tests/Unit/GeofenceTest`
  - changed: `tests/Pest.php`

Docs:
- new: `docs/api/shifts.md`, this report
- updated: `docs/api/README.md`, `docs/database/README.md`, `docs/architecture/overview.md`,
  `docs/decisions/0008-offline-operation-policy.md` (implementation notes)

## DATABASE CHANGES

| Table | Integrity in PostgreSQL |
|---|---|
| `system_settings` | PK key; value must be a JSON scalar |
| `shifts` | Unique `shift_uuid`; FKs attendant, location, device, assignment (restrict); CHECK status, geofence values, closed ⇒ ended_at_server, forced ⇒ closer + reason, end ≥ start, flags is an array; **one OPEN shift per attendant** (partial unique index) |

Both migrations are reversible and ran cleanly on the dev and test databases.

## API

| Endpoint | Notes |
|---|---|
| `GET /api/v1/bootstrap` | Offline cache with `valid_until`; tariff schedule rule documented for the device |
| `POST /api/v1/shifts/start` | 201 created / 200 replay / 409 `SYNC_CONFLICT` / 409 `SHIFT_ALREADY_OPEN` / 403 `LOCATION_NOT_ALLOWED` (online only) / 403 `DEVICE_NOT_ALLOWED` / 422 |
| `POST /api/v1/shifts/end` | 200 / 200 replay / 200 forced / 409 / 422 / 404 |
| `GET /api/v1/shifts/active`, `/{uuid}` | Read |

All of them sit behind `auth:sanctum` + `mobile.attendant` + `mobile.device`.
Contract: [docs/api/shifts.md](../api/shifts.md).

Verified through nginx with the seeded attendant:
1. approve the device;
2. login;
3. bootstrap returns `DEV-ALUN-01` and its tariffs;
4. start returns **201**, geofence `INSIDE`;
5. the same start again returns `replayed: true`;
6. end returns `CLOSED`, geofence `INSIDE`.

## TESTS

- Command: `docker compose exec app composer check`
- Result: **301 passed (1221 assertions)**, about 131 s. Phase 3 adds 53 tests.

| Suite | Covers |
|---|---|
| ShiftApiTest (25) | Start with GPS/geofence/audit; idempotent replay; conflict on same UUID; one open shift (app 409 + DB 23505); strict online validation ×3; offline accepted with flags; offline uses the real start day; 7 signal cases (clock skew online/offline, far outside, mock, poor accuracy, no GPS, edge within accuracy); device-not-approved-at-start; pending device refused; time offset required; end with audit; idempotent end + conflict; end before start; other attendant 404; force-closed reported; active/show endpoints |
| BootstrapTest (3) | Full payload (assignment, location, current + upcoming + location-specific tariffs, window excludes far future and other types, settings, valid_until = +72 h); no assignment; pending device refused |
| ShiftAdminTest (7) | List + flagged filter + role access; supervisor force close (reason required, audit, not twice); 4 other roles refused; overdue job flags once, honours a changed threshold |
| SettingsTest (7) | Defaults; update within bounds + audit + no-op not audited; out of range / unknown key; 3 roles refused; DB scalar CHECK |
| Unit GeofenceTest (11) | Haversine; 9 classification cases; rounded distance |

**Mutation check:** treating offline starts like online ones makes 3 ShiftApiTest cases fail.

Issues found during the phase:
1. Test data built invalid rows: two OPEN shifts for one attendant, overlapping tariffs, and a
   non-array flags value. The database constraints refused each one, which confirms they work.
   The test fixtures were fixed.
2. A Carbon 3 signed-diff assertion in a test was fixed.

## STATIC ANALYSIS

- **Pint:** PASS (218 files).
- **Larastan level 8:** `[OK] No errors`. One list-type issue in `scheduleFor()` was fixed.
- **Architecture tests:** pass.
- **TypeScript** strict build: passes.

## SECURITY NOTES

- Shift endpoints require an ACTIVE device and an operational attendant on every call.
  Attendants cannot read or end other attendants' shifts (404, not 403, so shift UUIDs cannot be probed).
- Idempotency uses server-side payload fingerprints. A replay never creates a second record or audit entry.
- Mock-location and geofence results are stored as signals (master doc §26). They do not block,
  so a spoofed "inside" position cannot be relied on, and they are left for review (Phase 9).
- Settings changes are limited to `system.configure` (Super Admin), bounded and audited.

## ARCHITECTURE DECISIONS / ADR

- ADR-0008 extended with implementation notes: the settings, the bootstrap as the precondition
  source, the flag catalogue, and force-close behaviour. No new ADR was needed.

## KNOWN LIMITATIONS

- A cash summary at shift end arrives with transactions and settlement (Phases 4/7).
- The anomaly review queue that consumes `review_flags` is Phase 9. For now, flags are visible
  on the Shift pages and filterable.
- "Impossible movement" (master doc §25) needs consecutive positions; it will use transaction
  GPS points in Phase 9.
- The device's mock-location detection is best effort (master doc §26); the server only records it.

## DEVIATIONS FROM APPROVED PLAN

- Added `offline_config_max_age_hours` (72), `max_clock_skew_minutes` (10) and
  `gps_max_accuracy_m` (100) settings, next to the two required by Q5. These are defaults that
  the owner can change in the UI.
- Shift API paths follow the master doc (`/shifts/start`, `/shifts/end`) with `shift_uuid` in
  the body, plus read endpoints.

## BLOCKERS

None. Owner confirmations:
1. `offline_config_max_age_hours` default of 72 h: how long may a phone work offline on cached data?
2. Force close is Supervisor-only (per §6.5 "review shift issue"). Should Dishub admins also have it?

## NEXT RECOMMENDED PHASE

Phase 4 — Cash Transaction (tariff snapshot, append-only cash ledger with derived balance,
transaction history, idempotency). Proceeding directly.
