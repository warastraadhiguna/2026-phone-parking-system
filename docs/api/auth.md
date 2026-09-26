# Mobile Authentication API

For the Android app (juru parkir). Design: [ADR-0005](../decisions/0005-mobile-authentication.md).
All responses use the [standard envelope](README.md#response-envelope). All endpoints need HTTPS in production.

## Token model

| Token | Format | Lifetime | Storage on device |
|---|---|---|---|
| Access token | `<id>\|ppat_<random>` (Sanctum) | `IDENTITY_ACCESS_TOKEN_TTL_MINUTES` (default 60 min) | Android Keystore-backed encrypted storage |
| Refresh token | `pprt_<64 random chars>` (opaque) | `IDENTITY_REFRESH_TOKEN_TTL_DAYS` (default 30 days, renewed on every refresh) | Android Keystore-backed encrypted storage |

- The server stores only SHA-256 hashes of both tokens.
- Both tokens are bound to the `device_uuid` sent at login. The app generates the UUID once
  per installation and keeps it.
- A new login on the same device ends that device's previous session.
- Each refresh **rotates** both tokens. The previous access token stops working immediately.
- **Retry safety:** if a refresh response is lost, the app may resend the same refresh token
  within `IDENTITY_REFRESH_REUSE_GRACE_SECONDS` (default 60 s). As long as the lost
  replacement was never used, the server issues a fresh pair and invalidates the lost one.
- **Reuse detection:** an old refresh token presented later (or after its replacement was
  used) is treated as theft. The whole session is revoked and the event is audited
  (`REFRESH_TOKEN_REUSE_DETECTED`).
- Sessions also end when an administrator deactivates the account or resets its password.

### Client behaviour

| Response | App action |
|---|---|
| `401 UNAUTHENTICATED` on any API call | Access token expired or revoked. Call `/auth/refresh`, then retry once. |
| `401 AUTH_INVALID` from `/auth/refresh` | Session is over. Show the login screen. Keep the unsynced local data. |
| `403 ACCOUNT_DISABLED` | Account inactive. Show the message and the login screen. |
| `429 RATE_LIMITED` | Wait `Retry-After` seconds. |
| Network error during refresh | Retry the **same** refresh token (grace window). |

Offline CASH work continues without a valid token. Sync resumes after a successful
refresh or login ([ADR-0008](../decisions/0008-offline-operation-policy.md)).

## `POST /api/v1/auth/login`

Throttled: 5/min per username+IP, 30/min per IP.

```json
{
  "username": "jp-000001",
  "password": "…",
  "device_uuid": "7d0c7a0e-3b7e-4c1e-9d55-4f7a2b1c9e01",
  "device_model": "Samsung A15",      // optional
  "android_version": "14",            // optional
  "app_version": "1.0.0"               // optional
}
```

The username is the attendant code in lower case.

`200`:

```json
{
  "success": true,
  "data": {
    "token_type": "Bearer",
    "access_token": "12|ppat_…",
    "access_token_expires_at": "2026-09-25T10:54:53+00:00",
    "refresh_token": "pprt_…",
    "refresh_token_expires_at": "2026-10-25T09:54:53+00:00",
    "user": {
      "id": 8, "username": "jukir.demo", "name": "Demo Juru Parkir", "account_type": "ATTENDANT",
      "roles": ["PARKING_ATTENDANT"],
      "permissions": ["mobile.payment.qris", "mobile.settlement.submit", "mobile.shift.operate", "mobile.transaction.create", "mobile.void.request"]
    },
    "device": { "uuid": "7d0c…", "status": "PENDING_APPROVAL", "status_label": "Menunggu Persetujuan" }
  },
  "meta": { "request_id": "…" },
  "error": null
}
```

Errors:

| Code | HTTP | When |
|---|---|---|
| `VALIDATION_FAILED` | 422 | Missing fields; `device_uuid` is not a UUID |
| `AUTH_INVALID` | 401 | Unknown user, wrong password, or a staff (non-attendant) account. The response never says which. |
| `ACCOUNT_DISABLED` | 403 | Correct password, but the account is `INACTIVE` or `SUSPENDED` |
| `RATE_LIMITED` | 429 | Too many attempts |

| `DEVICE_NOT_ALLOWED` | 403 | The device is registered to another attendant, or is `REVOKED`/`LOST` |

### Device approval (Phase 2, [ADR-0010](../decisions/0010-master-data-rules.md))

- An unknown `device_uuid` is registered as `PENDING_APPROVAL` at login. Login **succeeds**,
  so the app can show "menunggu persetujuan". `data.device.status` tells the app which state it is in.
- Operational endpoints (shift, transactions, sync; Phase 3+) require an `ACTIVE` device. Until
  then they return `403 DEVICE_NOT_ALLOWED` (message: "Perangkat menunggu persetujuan admin.").
- A revoked or lost device ends its sessions at once. Refresh then fails with `DEVICE_NOT_ALLOWED`.

## `POST /api/v1/auth/refresh`

Throttled: 30/min per IP.

```json
{ "refresh_token": "pprt_…", "device_uuid": "7d0c7a0e-3b7e-4c1e-9d55-4f7a2b1c9e01" }
```

`200`: same token fields as login, without `user`.
Errors: `AUTH_INVALID` (401: unknown, expired, revoked, reused, or other device),
`ACCOUNT_DISABLED` (403), `VALIDATION_FAILED` (422), `RATE_LIMITED` (429).

## `POST /api/v1/auth/logout`

Header `Authorization: Bearer <access_token>`. Ends the session: the access token and all
refresh tokens of that login. `200 {"data":{"logged_out":true}}`.

## `GET /api/v1/auth/me`

Header `Authorization: Bearer <access_token>`. Returns the app's home-screen data:

```json
{
  "user": { … same as login … },
  "attendant": { "attendant_code": "JP-000001", "name": "…", "status": "ACTIVE", "expired_at": null },
  "device": { "uuid": "…", "status": "ACTIVE", "status_label": "Aktif" },
  "assignment": { "id": 1, "location_code": "DEV-ALUN-01", "location_name": "…", "effective_from": "2026-09-25", "effective_until": null }
}
```

`assignment` is today's assignment (WIB) or `null`.
Errors: `UNAUTHENTICATED` (401), `ACCOUNT_DISABLED` (403), `FORBIDDEN` (403: not a mobile
attendant token).

## Audit trail

`LOGIN`, `LOGIN_FAILED` (with reason, visible only to auditors), `TOKEN_REFRESHED`,
`REFRESH_TOKEN_REUSE_DETECTED` and `LOGOUT` are recorded with device UUID, client IP and request ID.
