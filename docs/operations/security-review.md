# Security Review (Phase 11)

Date: 2026-09-26. Scope: backend (API, admin web, database), Android app, deployment
artefacts. Method: checklist of master doc §33/§54 against code and tests, plus targeted checks.
This is an internal review and **not** a substitute for an independent penetration test before
go-live, which is recommended.

## §33 checklist

| Requirement | Status | Evidence |
|---|---|---|
| HTTPS only | ✅ at deployment | The production boot guard refuses a non-https `APP_URL`; HSTS on HTTPS responses (`SecurityHeaders`); the Android release build refuses cleartext (network security config); TLS at the edge (deployment.md) |
| Token authentication | ✅ | Sanctum access tokens (60 min) with ability `mobile`, bound to the device (ADR-0005) |
| Refresh mechanism | ✅ | Rotating hashed refresh tokens with reuse detection and a grace window (`MobileAuthTest`) |
| RBAC | ✅ | Role matrix in code (`Role::permissions()`), `can:` middleware on every admin route and mobile write; matrix tests (separation of duties, read-only roles) |
| Rate limiting | ✅ | Mobile login per username/IP, refresh per IP, admin login (`StaffLoginRequest`), webhook per IP |
| Password hashing | ✅ | bcrypt (Laravel default); policy ≥ 10 characters with letters and digits; no plaintext anywhere; the Android app never stores passwords |
| Secure secrets | ✅ | Everything from env/secret manager; `.env*` ignored and excluded from the image (`.dockerignore`); the Midtrans key never logged (redactor tests) |
| No secret in repository | ✅ | `.env.example` has empty keys; the demo password is a documented local-only value; `git status` shows no `.env` |
| Validation | ✅ | FormRequest/validator on every input; DB CHECK constraints as a second line |
| SQL injection | ✅ | Query builder and bindings everywhere. Raw SQL fragments come only from code constants. The reconciliation window literals are server-generated timestamps (documented) |
| XSS | ✅ | React escapes output; strict **Content-Security-Policy** (`script-src 'self'`, no inline scripts, `object-src 'none'`, `frame-ancestors 'none'`); the Leaflet popup HTML is escaped; the audit metadata is shown as JSON text |
| CSRF (web) | ✅ | Laravel session + CSRF on the admin web (ADR-0004); mobile uses bearer tokens only |
| Audit login | ✅ | `LOGIN`, `LOGIN_FAILED`, `LOGOUT`, token refresh and reuse detection are audited with IP, device and request ID |
| Webhook verification | ✅ | SHA512 signature before anything else; amount check; idempotent; unauthenticated payloads are never stored (`QrisFlowTest`) |

## Additional checks and fixes made in this phase

| Topic | Finding | Action |
|---|---|---|
| Browser headers | No CSP or HSTS | **Fixed:** `SecurityHeaders` middleware (CSP, HSTS, nosniff, Referrer-Policy, Permissions-Policy); tests |
| Production misconfiguration | `APP_DEBUG=true` in production would leak stack traces | **Fixed:** `ProductionGuard` refuses to boot (debug on, or non-https URL); the fake payment gateway is already refused |
| Database privileges | The application used the schema owner, so a compromised app could DROP triggers | **Fixed:** `docker/postgres/production-roles.sql`. The runtime role `pati_app` has no DDL, no TRUNCATE, no UPDATE/DELETE on append-only tables and no DELETE on financial tables. **Verified** on a scratch database: every forbidden statement fails, and all scheduled jobs work |
| Container user | The dev image runs FPM as root (bind-mount permissions) | The production target runs as `www-data`, with code baked in and no dev dependencies |
| Report exports | CSV formula injection; data leakage | Neutralised cells; requester-only download; 7-day retention; the audit-log report needs `audit.view`; metadata excluded |
| File uploads | Photos and proofs | Image validation (mime and size), private disk, served with `nosniff` only to authorised roles |
| Mobile storage | Tokens at rest | Android Keystore AES-GCM; backups and device transfer disabled |
| Mock location / GPS fraud | Signals exist | Mock flag, geofence, impossible movement → review queue (Phase 9) |

## Residual risks and recommendations (owner decision)

1. **No second factor for staff accounts.** Recommended for Super Admin, Finance and Supervisor
   before go-live, for example TOTP.
2. **Midtrans webhook source IP allowlist** is not enforced (the signature is). Optional
   hardening at the edge once the official Midtrans IP list is confirmed.
3. **Session encryption** is off (sessions live in Redis with a password). Enable
   `SESSION_ENCRYPT=true` if Redis is shared with other systems.
4. **Independent penetration test** of the admin web and the API before production.
5. **Dependency updates:** run `composer audit` and `npm audit` in CI. Today both report no
   known advisories.
6. **Retention period** for personal data (NIK, phone, photos) is not yet defined (§62 #13).
