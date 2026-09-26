# ADR-0010: Master data rules (locations, attendants, devices, assignments, tariffs)

- Status: Accepted (Phase 2 implementation defaults; owner items marked **[owner]**)
- Date: 2026-09-25

## Context

Phase 2 builds the registries that every later phase depends on. Several details are not fixed
by the master document. They were decided using technical best practice, and the defaults below
are marked where the owner should confirm them.

## Decision

### Locations

- `location_code` is entered by an admin, stored upper-case (`^[A-Z0-9][A-Z0-9-]{2,29}$`),
  unique, and **immutable** (it appears on reports).
- Location types: `ON_STREET` (tepi jalan umum), `OFF_STREET` (tempat khusus parkir) and
  `EVENT` (insidentil). **[owner]** Confirm against the Perda retribusi parkir.
- Geofence radius is required, 5–1000 m, per location. The value is set by the admin; the
  official radii are an open owner item (master doc §62 #7).
- Status: `ACTIVE`, `INACTIVE`, `SUSPENDED`. Non-active locations accept no new assignments
  (and, from Phase 3, no shifts). Locations are never deleted.

### Attendants

- An attendant is registered together with its `ATTENDANT` login account, in one transaction.
- `attendant_code` comes from a database sequence (`JP-000001`, …). Its lower-case form is the
  login username. **[owner]** Confirm the official code format (§62 #8 is the analogous item for transactions).
- NIK is required, 16 digits, unique, and treated as personal data: masked in lists, shown in
  full only to `attendants.manage`, never written to logs or audit metadata.
- The attendant status is the source of truth. The login account mirrors it
  (`ACTIVE`→`ACTIVE`, `SUSPENDED`→`SUSPENDED`, `INACTIVE`/`EXPIRED`→`INACTIVE`), which ends
  mobile sessions. Attendant account status cannot be changed on the Users page.
- An optional registration validity (`expired_at`) is enforced by the daily `attendants:expire`
  job (00:05 WIB).
- Photos are stored on the private disk and served only through an authorized route.

### Devices (implements ADR-0005)

- A `device_uuid` belongs to exactly one attendant, globally. A phone shared between attendants
  must be registered per attendant (reinstall or app reset gives a new UUID). **[owner]**
  Confirm whether shared phones occur.
- First login with an unknown UUID registers it as `PENDING_APPROVAL`. Login succeeds so the app
  can show the waiting state, but operational endpoints (`mobile.device` middleware) return
  `DEVICE_NOT_ALLOWED`.
- Approval requires that the attendant has no other `ACTIVE` device (application check plus a
  partial unique index). The old device is revoked explicitly first.
- `REVOKED` and `LOST` are final. Deactivation ends every session on that device immediately.
  Login and refresh from such a device are refused.
- The Identity module stays independent: it calls the `MobileDeviceGate` contract, which the
  Device module implements (`DeviceGatekeeper`).

### Assignments

- An inclusive date range of WIB calendar days. An attendant has **at most one location per
  day** (PostgreSQL exclusion constraint). A location may have many attendants. **[owner]**
  Confirm that an attendant never serves two locations on the same day.
- No backdating (start ≥ today). End dates can be moved, but never before today, so work already
  recorded under an assignment stays attributable.
- Only assignments that have not started can be cancelled (kept with a reason). Running ones are ended instead.

### Tariffs

- Scope = vehicle type + location type, optionally narrowed to one location. A location-specific
  tariff wins over the location-type tariff.
- Workflow `DRAFT → APPROVED | REJECTED`. **Four eyes:** the approver must differ from the creator
  (application check plus a CHECK constraint).
- **No retroactive tariffs:** approval requires `effective_from` in the future, so no recorded
  transaction could ever have been priced by it.
- Approving a new version closes the open-ended approved tariff of the same scope at the new
  start. That is the only change the database trigger allows on an approved row. Approved rows
  are otherwise frozen, and tariffs are never deleted or truncated.
- Approved tariffs of one scope never overlap in time (exclusion constraint).
- `TariffResolver::resolve()` is the single way to price a transaction (Phase 4). No tariff
  means `TARIFF_NOT_FOUND`.
- Amounts are integer rupiah. No official tariff values are in the system; the seeder creates
  `DEV-ONLY — bukan tarif resmi` values locally.

## Consequences

- Master-data invariants that affect money or attribution are enforced by PostgreSQL itself
  (exclusion constraints, partial unique index, CHECKs, triggers), not only by application code.
- The `btree_gist` extension is required. It is a trusted extension, so the database owner can create it.
- Timestamps are stored with second precision (Laravel's default date format). Comparisons
  across tariff boundaries use stored values.
