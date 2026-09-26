# ADR-0005: Mobile authentication and device approval

- Status: Accepted (owner decision Q4, 2026-09-25). Implementation: Phase 1 (auth), Phase 2 (devices).
- Date: 2026-09-25

## Context

Attendants use one Android device in the field, often with poor connectivity (master doc §34,
§35). Tokens must be revocable, bound to a device, and must never expose passwords.
Unapproved devices must not operate.

## Decision

### Tokens

- **Access token:** Laravel Sanctum personal access token, short-lived (target around 60
  minutes, configurable), sent as `Authorization: Bearer`.
- **Refresh token:** an opaque random value stored **hashed** in `mobile_refresh_tokens`,
  bound to `(user, device)`. It is **rotated** on every use.
  **Reuse detection:** presenting an already-used refresh token revokes the whole token
  family and is recorded in the audit log.
- Tokens are stored on the device in Android secure storage (Keystore-backed). Passwords are
  never stored on the device.
- Login, refresh, logout and revocation are audited. Rate limits apply to login and refresh.
- Rejected: Laravel Passport / full OAuth2 (unnecessary complexity for a first-party app);
  JWT (hard to revoke).

### Device approval lifecycle

```text
NEW DEVICE (first login) ──► PENDING_APPROVAL ──(authorized admin activates)──► ACTIVE
ACTIVE ──► REVOKED | LOST
```

- `PENDING_APPROVAL` is added to the master document's statuses (`ACTIVE`, `REVOKED`, `LOST`).
- A device that is not `ACTIVE` **cannot** start shifts, create transactions, or sync
  operational data. Such requests fail with `DEVICE_NOT_ALLOWED`. It may still log in and see
  its own pending status.
- **One `ACTIVE` device per attendant**, enforced by a partial unique index
  `devices(attendant_id) WHERE status = 'ACTIVE'`. Activating a new device requires revoking
  the old one first, as an explicit admin action.
- Revoking a device also revokes its refresh tokens. Access tokens expire within their short TTL.

## Consequences

- A lost phone can be cut off centrally within one access-token lifetime.
- Offline work continues while the access token is expired. Sync resumes after refresh
  ([ADR-0008](0008-offline-operation-policy.md)).
- The refresh flow is custom code and needs thorough tests (rotation, reuse, revocation, races).

## Implementation notes (Phase 1)

- Implemented by `StartMobileSession`, `RefreshMobileSession`, `EndMobileSession` and
  `RevokeMobileSessions` in the Identity module. Contract: [docs/api/auth.md](../api/auth.md).
- Defaults (env-configurable): access token 60 min, refresh token 30 days (renewed on each
  rotation), token prefixes `ppat_` / `pprt_` so secret scanners can recognise them.
- A **grace window (60 s)** was added to reuse detection. Re-presenting a just-rotated refresh
  token whose replacement was never used is treated as a retry after a lost response, and the
  unused replacement is superseded. Without it, a flaky mobile network would log attendants out.
- Sessions are bound to the `device_uuid` given at login. A new login on the same device
  revokes that device's previous session. Until the Device module exists (Phase 2), an
  attendant may be logged in on several devices. Phase 2 adds the `PENDING_APPROVAL` gate and
  the one-`ACTIVE`-device rule at login and refresh.
- Roles and permissions (spatie/laravel-permission) are registered on the `web` guard only.
  They still apply to Sanctum-authenticated users, because permission checks resolve through
  the user model.
- Password policy for all accounts: 10–128 characters, at least one letter and one digit.