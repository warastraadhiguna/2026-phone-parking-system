# Implementation Report — Phase 1

```text
PHASE:
Phase 1 — Identity & Access

STATUS:
DONE
```

Date: 2026-09-25. Builds on [Phase 0](phase-0.md).

## IMPLEMENTED

- **Accounts:** `users` for `STAFF` (admin web only) and `ATTENDANT` (mobile only), with
  status `ACTIVE`/`INACTIVE`/`SUSPENDED`. Usernames are lower-case and case-insensitive.
  Accounts are never hard-deleted. Password policy: 10–128 characters, letters and digits.
- **Roles & permissions:** 8 roles (master doc §6) and a catalogue of 38 permissions covering
  every phase. The role → permission matrix is defined in code (`Role::permissions()`),
  applied by `identity:sync-roles` (idempotent, repairs drift, audited), and shown read-only
  at `/roles`. Separation of duties is enforced by tests.
- **Admin web authentication:** Laravel session + CSRF.
  - Login and logout pages in Indonesian.
  - Throttling per username+IP (5/min) and per IP (30/min).
  - Session regenerated on login.
  - Attendants and inactive accounts refused; generic error for wrong credentials.
  - Deactivated users logged out on their next request.
- **Mobile authentication API:** `/api/v1/auth/login`, `refresh`, `logout`, `me`.
  - Sanctum access token (60 min) plus a rotating, hashed refresh token (30 days) bound to
    `device_uuid`.
  - Reuse detection revokes the token family. A 60-second grace window covers retries after a
    lost response.
  - A new login on the same device replaces the old session.
  - Throttled login and refresh; staff tokens and admin sessions are refused.
- **User management (Super Admin):** list with search and filters (Auditor has read access),
  create staff, edit profile and roles, change status with reason, reset password. Rules:
  - staff and attendant roles cannot be mixed;
  - the last active Super Admin cannot be removed;
  - admins cannot deactivate themselves;
  - deactivation and password reset end mobile sessions immediately.
- **Audit trail** (first append-only table in use): `audit_logs` with actor, action, entity,
  metadata (secrets redacted), IP, device and request ID. Written only through
  `RecordAuditEvent`, inside the same transaction as the change it records. Audited events:
  - `LOGIN`, `LOGIN_FAILED` (with internal reason), `LOGOUT`;
  - `TOKEN_REFRESHED`, `REFRESH_TOKEN_REUSE_DETECTED`;
  - `USER_CREATED`, `USER_CHANGED`, `USER_STATUS_CHANGED`, `USER_ROLES_CHANGED`, `USER_PASSWORD_RESET`;
  - `ROLE_PERMISSIONS_SYNCED`.
- **Bootstrap and operations:**
  - `identity:create-super-admin`: interactive password, or a generated one shown once. No
    default credentials exist.
  - `DatabaseSeeder`: demo account per role, local/testing only.
  - Scheduled pruning of expired access tokens and old refresh tokens.
- **Request context:** client IP (via `TRUSTED_PROXIES`) and device UUID are made available
  to domain code as hidden Laravel Context. nginx overwrites any client-supplied `X-Forwarded-For`.
- **Admin frontend:**
  - Control Center layout with permission-aware navigation and flash messages.
  - Pages: Login, Home, Users (Index/Create/Edit), Roles.
  - Shared, reusable UI components; WIB time formatting; Indonesian validation messages (`lang/id`).

## FILES CHANGED

Backend (new unless noted):
- `app/Domain/Identity/`
  - `Enums/` (AccountType, UserStatus, Role, Permission, RevokeReason)
  - `Models/` (User, moved from `app/Models`; MobileRefreshToken)
  - `Actions/` (AuthenticateUser, StartMobileSession, RefreshMobileSession, EndMobileSession,
    RevokeMobileSessions, CreateUser, UpdateUser, ChangeUserStatus, ResetUserPassword, SyncRolePermissions)
  - `Data/` (MobileTokenPair, MobileLogin, MobileTokenName)
  - `Exceptions/` (InvalidCredentials, AccountDisabled, InvalidRefreshToken, UserRuleViolation)
  - `Internal/` (MobileTokenIssuer, MobileTokenIssue, UserRules)
  - `Console/` (SyncRolesCommand, CreateSuperAdminCommand)
- `app/Domain/Audit/` — `Enums/` (AuditAction, ActorType), `Models/AuditLog`, `Actions/RecordAuditEvent`
- `app/Support/RequestContext/RequestContext.php`; `app/Support/Errors/ErrorCode.php` (changed: `ACCOUNT_DISABLED`)
- `app/Http/Api/V1/Identity/{AuthController, Requests/MobileLoginRequest, Requests/MobileRefreshRequest}.php`
- `app/Http/Admin/{DashboardController, Identity/LoginController, Identity/UserController, Identity/UserStatusController, Identity/UserPasswordController, Identity/RoleController}.php`,
  `app/Http/Admin/Identity/Requests/{StaffLoginRequest, StoreUserRequest, UpdateUserRequest}.php`
- `app/Http/Middleware/{CaptureRequestContext, EnsureActiveStaff, EnsureMobileAttendant}.php`;
  changed: `HandleInertiaRequests.php` (auth + flash props)
- Changed: `app/Providers/AppServiceProvider.php` (password policy, rate limiters, trusted proxies),
  `bootstrap/app.php` (commands, middleware aliases, redirects, web error mapping)
- Config: `config/identity.php` (new); changed `config/auth.php`, `config/app.php` (`trusted_proxies`),
  `config/sanctum.php` (bearer only, no SPA route, token prefix); published `config/permission.php`
- Routes: `routes/web.php`, `routes/api.php`, `routes/console.php` (pruning)
- Migrations: `2026_09_25_100000_create_users_table` (replaces the Laravel default),
  `…100100_create_personal_access_tokens_table`, `…100200_create_permission_tables`,
  `…100300_create_mobile_refresh_tokens_table`, `…100400_create_audit_logs_table`
- `database/factories/UserFactory.php`, `database/seeders/DatabaseSeeder.php`
- `lang/id/{auth,validation}.php`
- Frontend (`resources/js`):
  - `types/index.ts`, `hooks/usePermissions.ts`, `lib/format.ts`
  - `Components/{ui.tsx, Pagination.tsx, FlashMessage.tsx, RoleCheckboxes.tsx}`
  - `Layouts/AdminLayout.tsx`
  - `Pages/{Auth/Login, Home, Users/Index, Users/Create, Users/Edit, Users/types, Roles/Index}`
- Tests:
  - `tests/Pest.php` (helpers)
  - `tests/Feature/Identity/{MobileAuthTest, AdminLoginTest, UserManagementTest, RolePermissionMatrixTest, IdentityCommandsTest, IdentitySchemaTest}.php`
  - `tests/Feature/Audit/AuditLogTest.php`
  - changed: `tests/Feature/Admin/InertiaBaselineTest.php`, `tests/Arch/ArchitectureTest.php`
- `composer.json`/`composer.lock`: `laravel/sanctum` 4.3, `spatie/laravel-permission` 8.3;
  `.env.example` (locale, identity, trusted proxies, dev seed password)

Infrastructure: `docker/nginx/default.conf` (overwrites `X-Forwarded-For`).

Docs:
- new: `docs/api/auth.md`, `docs/architecture/roles-and-permissions.md`, this report
- updated: `docs/api/README.md`, `docs/database/README.md`, `docs/architecture/overview.md`,
  `docs/coding-conventions.md`, `docs/decisions/0005-mobile-authentication.md`, `README.md`, `CLAUDE.md`

## DATABASE CHANGES

| Table | Notes |
|---|---|
| `users` | Replaces the Laravel default. CHECK: username format (lower-case), account type, status, lower-case e-mail. Unique username and e-mail. |
| `personal_access_tokens` | Sanctum; `timestamptz` columns; SHA-256 hashes only |
| `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` | spatie; managed only by `identity:sync-roles` |
| `mobile_refresh_tokens` | Hash (unique), family, device, access-token link, replacement link, expiry, used/revoked + reason (CHECK). FK user `RESTRICT`. |
| `audit_logs` | **Append-only (triggers)**. CHECK actor type, actor consistency, and metadata is an object. FK actor `RESTRICT`. `inet`, `uuid`, `jsonb`. Indexes on entity, actor+time, action+time, time. |

Dropped from the Laravel skeleton: `password_reset_tokens`, `sessions` (no e-mail reset; sessions live in Redis).
All migrations are reversible (`migrate:fresh` and `down()` verified via `RefreshDatabase` runs).

## API

| Method | Path | Notes |
|---|---|---|
| POST | `/api/v1/auth/login` | `{username, password, device_uuid}` → token pair + user. 401 `AUTH_INVALID`, 403 `ACCOUNT_DISABLED`, 422, 429. |
| POST | `/api/v1/auth/refresh` | `{refresh_token, device_uuid}` → rotated pair. 401 `AUTH_INVALID` (unknown/expired/revoked/reused/other device). |
| POST | `/api/v1/auth/logout` | Bearer → ends the session (access token and refresh family). |
| GET | `/api/v1/auth/me` | Bearer → user, roles, permissions. |

Admin web routes: `/login`, `/logout`, `/`, `/users`, `/users/create`, `/users/{id}/edit`,
`PUT /users/{id}`, `PUT /users/{id}/status`, `PUT /users/{id}/password`, `/roles`.
Contract: [docs/api/auth.md](../api/auth.md).

Verified through nginx:
- CSRF missing → `419`.
- Wrong password → back to login, `LOGIN_FAILED` audited.
- Correct login → `/`, `LOGIN` audited.
- `/users` as Super Admin → Inertia page; as Operator → `403`.
- Logout → `LOGOUT` audited; protected pages redirect to login.
- Mobile login, `/me`, and a staff account refused on mobile.

## TESTS

- Command: `docker compose exec app composer check` (Pint + Larastan + Pest on PostgreSQL 17)
- Result: **185 passed (722 assertions)**, about 130 s. Phase 1 adds 100 tests.

| Suite | Covers |
|---|---|
| MobileAuthTest (27) | Token shape, hash-only storage, device binding, audit fields, case-insensitive username, wrong password / unknown user, staff refused, inactive refused, validation, throttling, re-login replaces session (other device unaffected), rotation, repeated rotation, **grace retry**, **reuse after grace → family revoked**, reuse after replacement used, other device refused, unknown/expired refresh, suspended mid-session, access token expiry at 60 min, non-mobile/staff tokens refused, web session cannot use the API, logout |
| AdminLoginTest (12) | Guest redirect, login page, session regeneration + audit, shared props, wrong credentials, attendant refused, suspended refused, throttling, logout audit, **deactivated mid-session is logged out**, authenticated redirect |
| UserManagementTest (29) | Access per role (view/manage/forbidden), filters, create (normalisation, hashing, audit without password), 8 validation cases, update + audit, no-op not audited, **last Super Admin protected**, second Super Admin removable, suspend attendant ends mobile sessions, no self-deactivation, reactivation, password reset ends sessions, password policy, role page access |
| RolePermissionMatrixTest (9) | Every permission granted; mobile-only for attendants; Auditor/Executive read-only; separation of duties around money; DB sync; idempotency; drift repair and stale-permission removal; artisan command |
| IdentityCommandsTest (5) | Super Admin creation (generated / interactive / policy / duplicate), seeder demo accounts, seeder refuses production |
| IdentitySchemaTest (8) | DB CHECK/UNIQUE on users and refresh tokens (SQLSTATE 23514/23505) |
| AuditLogTest (8) | Actor/IP/device/request ID captured, system actor, secret redaction, model-level immutability, **DB-level UPDATE/DELETE/TRUNCATE rejected (23001)**, actor-consistency CHECK |

The tests found three real defects during the phase, all fixed:

1. The "last Super Admin" guard used `FOR UPDATE` with `COUNT`, which PostgreSQL rejects. It
   would have been a 500 error in production.
2. Empty audit metadata was stored as a JSON list. The CHECK constraint caught it.
3. Test isolation issues: stale in-memory user after `actingAs`; the production seeder prompt.

**Mutation check:** with the grace window set to 0, the grace-retry test fails as expected.

## STATIC ANALYSIS

- **Pint:** `PASS` (118 files).
- **Larastan level 8:** `[OK] No errors`. There is one inline, justified ignore
  (`larastan.noUnnecessaryCollectionCall` in `UserRules`: PostgreSQL forbids `FOR UPDATE` with
  aggregates). The first run found 8 issues, fixed properly: generics, list types,
  `env()` in `bootstrap/app.php` moved to config, and a `sanctum.php` type.
- **Architecture tests:** 25 passed. New rules: spatie models and `MobileRefreshToken` are used
  only in Identity; `AuditLog` is used only in Audit (all writes go through `RecordAuditEvent`).
- **TypeScript** strict build: passes.

## SECURITY NOTES

- Passwords: bcrypt (`hashed` cast), rehash on login when needed; never logged or audited (tests assert this).
- Tokens: only SHA-256 hashes stored. Access tokens are short-lived. Refresh tokens rotate and
  have reuse detection. Both are device-bound. `Cache-Control: no-store` on token responses.
  Prefixes `ppat_`/`pprt_` make leaked tokens recognisable to secret scanners.
- Account enumeration: unknown user, wrong password and wrong channel give the same response,
  and unknown users still pay a hash computation (timing). The reason is visible only in the audit trail.
- Channel isolation: staff cannot use the mobile API; attendants cannot use the Control
  Center; admin sessions never authenticate API calls (Sanctum guard `[]`, SPA route disabled).
- Brute force: throttling per username+IP and per IP, on both channels.
- Session fixation: session regenerated on login, invalidated on logout. There is no
  "remember me". Session idle lifetime is 120 minutes (`SESSION_LIFETIME`).
- Authorization is enforced on the server for every route (`can:` middleware, account-type/status
  middleware). The frontend hides what the user cannot use, but that is cosmetic.
- Audit rows are immutable at the model and DB levels. The client IP depends on
  `TRUSTED_PROXIES`, and nginx overwrites spoofed `X-Forwarded-For`.
- No default or production credentials exist. The demo password is a labelled local-only
  `.env` value, and the seeder refuses non-local environments.

## ARCHITECTURE DECISIONS / ADR

- ADR-0005 updated with implementation notes: TTLs, token prefixes, **grace window for
  refresh retries** (new detail), device binding before Phase 2, guard setup, password policy.
- The role matrix is documented in `docs/architecture/roles-and-permissions.md` (verified
  against code: all 38 permission rows match).
- No other ADR changed.

## KNOWN LIMITATIONS

- **Device approval is not enforced yet.** An attendant can log in from any device, and
  several devices at once. Phase 2 (Device module) adds `PENDING_APPROVAL` and one `ACTIVE`
  device per attendant at login and refresh, as planned in ADR-0005.
- Attendant accounts cannot be created in the UI yet. That comes with attendant registration
  in Phase 2, which will call `CreateUser`. For now: seeder or code.
- No audit log viewer yet (Phase 9). Records are stored and tested.
- No forced password change on first login, no password expiry, no self-service reset (not
  in the requirements; see Blockers/Questions).
- Readiness does not check spatie's permission cache. It lives in Redis; losing it only
  causes a reload from PostgreSQL.
- The debug error page in the test environment is very slow (about 90 s) when an unexpected
  500 occurs. This was noticed while diagnosing the `FOR UPDATE` defect. It only affects
  failing tests.
- Still no git commit (files untracked), as in Phase 0.

## DEVIATIONS FROM APPROVED PLAN

- **Grace window for refresh-token reuse** (60 s) was added to ADR-0005's reuse detection, for
  field reliability. It is documented and tested.
- **Laravel default `users` migration replaced** (not altered), and `password_reset_tokens`
  and `sessions` were dropped. This was safe because it had only run on local dev databases.
- **Permission catalogue includes future phases** (38 permissions), so the full matrix can be
  reviewed now. Permissions for later phases guard nothing yet.
- **UI language Indonesian**; `APP_LOCALE=id` with partial Indonesian validation messages
  (fallback English). API error messages stay English, because clients use error codes.
- **Frontend `lib/` folder** added next to the approved `Pages/Components/Layouts/hooks/types`
  (pure helpers only).
- **New error code `ACCOUNT_DISABLED`** (403).

## BLOCKERS

None. Owner decisions that would refine Phase 1 (defaults are in place):

1. **Role matrix review:** see the "Open points for the owner" section in
   [roles-and-permissions.md](../architecture/roles-and-permissions.md). Main questions: who
   approves tariffs; whether Executive Viewer should see reports; whether Operator is
   void-request-only.
2. **Attendant credential:** currently username + password (min. 10 characters, letters and
   digits). Would field staff be better served by a numeric PIN (with stricter lockout)?
3. **First-login password change / password expiry** for staff: required by Pemkab policy?

## NEXT RECOMMENDED PHASE

Phase 2 — Master Data (parking locations, attendants, devices with approval workflow,
assignments, versioned tariffs).

**Phase 2 has not been started. Waiting for explicit approval.**
