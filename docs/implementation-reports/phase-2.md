# Implementation Report — Phase 2

```text
PHASE:
Phase 2 — Master Data

STATUS:
DONE
```

Date: 2026-09-25. The owner authorised continuing through the phases without waiting for approval
between them; open owner items are listed under Blockers and in ADR-0010.

## IMPLEMENTED

- **Parking locations:**
  - registry with immutable upper-case code, coordinates and required geofence radius (5–1000 m);
  - three types (`ON_STREET`, `OFF_STREET`, `EVENT`), capacities, and status with a reason;
  - admin list with filters, create page, and a detail page showing today's attendants and the
    tariffs currently in effect.
- **Juru parkir:**
  - registration creates the attendant **and** its mobile login account in one transaction;
  - code `JP-000001…` comes from a DB sequence, and its lower-case form is the username;
  - NIK is required, unique, masked for non-managers and kept out of logs and audit;
  - the attendant status is mirrored on the login account, so suspending an attendant ends its
    mobile sessions;
  - registration validity with a daily `attendants:expire` job;
  - private photo upload, served through an authorized route.
- **Devices (ADR-0005 completed):**
  - first login registers the device as `PENDING_APPROVAL`, and login still succeeds;
  - operational endpoints are gated by the new `mobile.device` middleware (`DEVICE_NOT_ALLOWED`);
  - admin approval queue; one `ACTIVE` device per attendant;
  - revoke / mark lost is final and ends that device's sessions at once, after which login and
    refresh are refused;
  - a device registered to another attendant is refused.
  - Identity stays independent through the new `MobileDeviceGate` contract.
- **Assignments:**
  - inclusive WIB date ranges, at most one location per attendant per day (DB exclusion
    constraint), several attendants per location;
  - no backdating; end dates cannot be moved before today;
  - only assignments that have not started can be cancelled;
  - `AssignmentLookup::currentFor()` for Phase 3, and `/api/v1/auth/me` returns today's assignment.
- **Tariffs:**
  - versioned `DRAFT → APPROVED | REJECTED`;
  - **four eyes** (approver ≠ creator, also a CHECK constraint);
  - **no retroactive approval**;
  - approving a new version closes the previous open-ended one automatically;
  - approved rows are frozen by a trigger, and there are no deletes;
  - no overlap per scope (exclusion constraint);
  - location-specific override beats the location-type tariff;
  - `TariffResolver` is the single pricing lookup for Phase 4.
- **Shared foundations:**
  - `RuleViolation` (a business-rule error shown as a form error on the web and as the
    envelope on the API);
  - `ChangeSet` (audit diffs);
  - `BusinessTime` (WIB calendar, UTC storage);
  - `Options` (enum → select options);
  - NIK added to log redaction.
- **Audit:** 17 new actions (location, attendant, device, assignment, tariff lifecycle), each
  written in the same transaction as the change.
- **Admin UI (Indonesian):** Lokasi, Juru Parkir (profile, status, photo, assignments,
  devices), Perangkat (approval queue), and Tarif (list, draft form, detail with approve/reject).
  Permission-aware navigation.
- **Dev seed:** built through the real Actions:
  - 2 demo locations;
  - 1 demo attendant `jp-000001` with an assignment;
  - DEV-ONLY tariffs for every location type, drafted by `dishub` and approved by `superadmin`.

## FILES CHANGED

Backend (new unless noted):
- `app/Domain/ParkingLocation/{Enums/LocationType, Enums/LocationStatus, Models/ParkingLocation, Data/LocationData, Actions/CreateLocation, Actions/UpdateLocation, Actions/ChangeLocationStatus}`
- `app/Domain/ParkingAttendant/`
  - `Enums/AttendantStatus`, `Models/ParkingAttendant`, `Data/AttendantData`
  - `Actions/{RegisterAttendant, UpdateAttendant, ChangeAttendantStatus, ExpireAttendants, SetAttendantPhoto}`
  - `Console/ExpireAttendantsCommand`
- `app/Domain/Device/{Enums/DeviceStatus, Models/Device, Services/DeviceGatekeeper, Services/DeviceAccess, Actions/ApproveDevice, Actions/DeactivateDevice}`
- `app/Domain/Assignment/{Enums/AssignmentStatus, Enums/AssignmentPhase, Models/Assignment, Actions/AssignAttendant, Actions/EndAssignment, Actions/CancelAssignment, Services/AssignmentLookup}`
- `app/Domain/Tariff/`
  - `Enums/VehicleType`, `Enums/TariffStatus`, `Models/Tariff`, `Data/TariffData`
  - `Internal/TariffRules`
  - `Actions/{CreateTariffDraft, UpdateTariffDraft, ApproveTariff, RejectTariff}`
  - `Services/TariffResolver`
- Identity (changed):
  - new: `Contracts/MobileDeviceGate`, `Data/MobileDeviceInfo`
  - changed: `Data/MobileLogin` (device summary), `Actions/AuthenticateUser` (admission hook),
    `Actions/StartMobileSession`, `Actions/RefreshMobileSession` (device checks),
    `Enums/RevokeReason` (+2 cases)
- Audit (changed): `Enums/AuditAction` (+17)
- Support (new): `Errors/RuleViolation`, `Database/ChangeSet`, `Time/BusinessTime`; changed: `Logging/SensitiveDataRedactor` (NIK)
- HTTP:
  - new: `Admin/Support/Options`, `Admin/ParkingLocation/{LocationController, LocationRequest}`,
    `Admin/ParkingAttendant/{AttendantController, AttendantRequest}`,
    `Admin/Assignment/AssignmentController`, `Admin/Device/DeviceController`,
    `Admin/Tariff/{TariffController, TariffRequest}`, `Middleware/EnsureActiveDevice`
  - changed: `Api/V1/Identity/AuthController` (device, me), `Api/V1/Identity/Requests/MobileLoginRequest`,
    `Admin/Identity/UserStatusController` (attendant guard)
- Wiring (changed): `bootstrap/app.php`, `app/Providers/AppServiceProvider.php`, `routes/web.php`
  (+24 routes), `routes/console.php` (expiry schedule), `config/app.php` (`business_timezone`)
- Migrations: `2026_09_25_200000_create_parking_locations_table`, `…200100_create_parking_attendants_table`,
  `…200200_create_devices_table`, `…200300_create_assignments_table`, `…200400_create_tariffs_table`
- `database/seeders/DatabaseSeeder.php` (rewritten)
- Frontend:
  - changed: `lib/format.ts` (+rupiah/date), `Components/ui.tsx` (+Field, Table, LinkButton,
    GeneralError, tones), `Layouts/AdminLayout.tsx` (menu)
  - new pages: `Locations/{Index, Create, Show, LocationFields, types}`, `Attendants/{Index, Create, Show}`,
    `Devices/Index`, `Tariffs/{Index, Form, Show, types}`
- Tests:
  - new: `tests/Feature/MasterData/{LocationTest, AttendantTest, DeviceTest, AssignmentTest, TariffTest}.php`
  - changed: `tests/Pest.php` (helpers), `UserManagementTest`, `IdentityCommandsTest`
- Infrastructure: `docker/php/Dockerfile` (+`gd` for image validation/processing)

Docs:
- new: `docs/decisions/0010-master-data-rules.md`, this report
- updated: `docs/api/auth.md`, `docs/database/README.md`, `docs/architecture/overview.md`,
  `docs/decisions/README.md`, `README.md`

## DATABASE CHANGES

| Object | Integrity enforced in PostgreSQL |
|---|---|
| extension `btree_gist` | Needed for the exclusion constraints below |
| `parking_locations` | Code format (upper-case), lat/long ranges, radius 5–1000, type, status, capacities |
| `parking_attendants` + `parking_attendant_code_seq` | Code format, one login per attendant (unique FK), 16-digit unique NIK, phone format, status, validity order |
| `devices` | Unique UUID; status; ACTIVE ⇒ approved_at; REVOKED/LOST ⇒ deactivated_at; **one ACTIVE per attendant** (partial unique index) |
| `assignments` | Period order, status; **no overlapping active periods per attendant** (EXCLUDE gist) |
| `tariffs` + `tariffs_guard()` | amount > 0, period order, approval fields, **approver ≠ creator**, **no overlap per scope** (EXCLUDE gist), approved rows frozen except closing validity, rejected final, no DELETE/TRUNCATE |

All migrations are reversible. `migrate:fresh` was verified twice in a row, and a rollback of
the five migrations was verified. The first attempt left a standalone sequence and function
behind; that was fixed with `OWNED BY` and `CREATE OR REPLACE`.

## API

| Change | Detail |
|---|---|
| `POST /api/v1/auth/login` | Accepts optional `device_model`, `android_version`, `app_version`; response adds `device {uuid, status, status_label}`; new error `DEVICE_NOT_ALLOWED` (403) |
| `POST /api/v1/auth/refresh` | Refused with `DEVICE_NOT_ALLOWED` once the device is revoked or lost (the session is revoked too) |
| `GET /api/v1/auth/me` | Adds `attendant`, `device`, today's `assignment` |
| middleware `mobile.device` | For operational endpoints from Phase 3. Requires an ACTIVE device and an operational attendant. |

Admin web routes: `locations*`, `attendants*` (incl. `/photo`, `/assignments`), `assignments/{id}/end|cancel`,
`devices*` (`approve`, `deactivate`), `tariffs*` (`approve`, `reject`), each behind its permission.

Verified through nginx as `dishub`: every Phase 2 page returns 200, and `/users` returns 403
(correct for that role). Mobile login of `jp-000001` registers the device as `PENDING_APPROVAL`.

## TESTS

- Command: `docker compose exec app composer check`
- Result: **248 passed (1003 assertions)**, about 115 s. Phase 2 adds 62 tests; 1 Phase 1 test
  was reworked.

| Suite | Covers |
|---|---|
| LocationTest (14) | Create + code normalisation + audit; 7 validation cases; update audits only real changes; code immutable; status with reason; view vs manage permissions; 3 DB CHECKs |
| AttendantTest (14) | Registration creates login account (username = code, role, hashed password), NIK not in audit; sequential codes; 5 validation cases; name sync + NIK change recorded without value; status mirroring + session revocation + reactivation; reason required; expiry job idempotent + system actor; private photo, served to viewers only, type validation; NIK masking; permissions |
| DeviceTest (11) | Pending at first login + audit + `/me` status + operational endpoint refused; approval opens endpoints; one ACTIVE per attendant (app + DB); revoke ends sessions and refuses login/refresh; refresh refused when the device is lost; device of another attendant refused; final states (REVOKED, LOST); reject pending; permissions; attendant account without registry refused |
| AssignmentTest (8) | Assign + lookup + audit; overlap refused (app + DB 23P01); several attendants per location; no backdating, inactive location or attendant refused; end not in the past; cancel only scheduled, cancelled periods free again; `/me` returns today's assignment; permission |
| TariffTest (15) | Draft has no effect; WIB→UTC; **four eyes** (app + DB); **no retroactive approval**; supersession closes the previous version; later-starting tariff blocks approval; location-specific wins; `TARIFF_NOT_FOUND`; **approved rows frozen / no delete / no truncate (23001)**; **no overlap (23P01)**; edit/reject only drafts; type/location consistency; permissions |

**Mutation check:** letting pending devices pass `DeviceAccess` makes the DeviceTest fail as expected.

Defects found and fixed during the phase:
1. The FormRequest method `data()` silently overrode Laravel's `InteractsWithData::data()`.
   Larastan caught it; it was renamed `toData()`.
2. `migrate:fresh` left a sequence and a trigger function behind. Fixed as noted above.
3. A test depended on the sequence value, which PostgreSQL never rolls back. The assertion was fixed.

## STATIC ANALYSIS

- **Pint:** PASS (182 files).
- **Larastan level 8:** `[OK] No errors`. The first run found 10 issues (including the `data()`
  override, a nullable extension, and a collection vs model type); all were fixed without ignores.
- **Architecture tests:** pass. The new modules are covered automatically by the `Internal` rule
  (`Tariff\Internal\TariffRules`).
- **TypeScript** strict build: passes.

## SECURITY NOTES

- NIK (personal data): masked in lists and for viewers without `attendants.manage`, redacted
  from logs (`identity_number`/`nik` keys), and recorded in audit only as "changed".
- Photos: private disk, authorized route, image type/size/dimension validation,
  `X-Content-Type-Options: nosniff`, never public URLs.
- Devices: stolen or lost phones are cut off immediately (sessions revoked, login/refresh
  refused). A device UUID cannot be reused by another attendant.
- Money-related master data is protected in the database: tariff four-eyes, no overlap, frozen
  approved rows, no deletes. No official tariff or credential values are in the repository.

## ARCHITECTURE DECISIONS / ADR

- **ADR-0010 Master data rules** (new): location code and types, attendant code = username,
  status mirroring, device UUID ownership, one location per attendant per day, the tariff
  workflow (four eyes, no retroactive approval, supersession, frozen rows).
- ADR-0005 is now fully implemented (device approval gate).

## KNOWN LIMITATIONS

- No map picker for coordinates. They are typed in; the monitoring map is Phase 10.
- Some table actions (end/cancel assignment, revoke device, reject tariff) use browser prompts
  for the date or reason. They work but are basic; a proper dialog component can follow.
- Tariffs apply per location type or per location; there are no time-of-day or duration-based
  tariffs (not in the MVP requirements).
- Timestamps are stored with second precision.
- A history of previous attendant photos is kept on disk, but there is no UI for it.

## DEVIATIONS FROM APPROVED PLAN

- Added `mobile.device` middleware and the `MobileDeviceGate` contract (needed to enforce device
  approval without coupling Identity to Device).
- Attendant login username = lower-case attendant code (the Phase 1 demo user `jukir.demo` is
  replaced by the seeded `jp-000001`).
- The Users page no longer changes the status of attendant accounts (Juru Parkir page only), to
  avoid the two statuses drifting apart.
- Added the PHP `gd` extension to the dev image.

## BLOCKERS

None. Owner confirmations (defaults are implemented; see ADR-0010):
1. Location types (tepi jalan / tempat khusus / insidentil) versus the Perda.
2. Official attendant code format (currently `JP-000001`).
3. Whether one phone is ever shared between attendants (currently not allowed).
4. Whether an attendant can serve two locations on the same day (currently not allowed).
5. Official tariffs and geofence radii (master doc §62 #1, #7). Only DEV-ONLY values exist.

## NEXT RECOMMENDED PHASE

Phase 3 — Shift (start/end shift online and offline-capable, active shift, location validation,
GPS capture, configurable thresholds). Proceeding directly, per the owner's instruction.
