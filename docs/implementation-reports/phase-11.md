# Implementation Report — Phase 11

```text
PHASE:
Phase 11 — Hardening

STATUS:
DONE (development roadmap complete; production go-live needs the owner items below)
```

Date: 2026-09-26. Continued without waiting for approval, as instructed by the owner.

## IMPLEMENTED

### Performance tests
- `tests/Performance/benchmark.php` seeds a synthetic month into a `*_perf` database (it refuses
  any other database) and times the heavy paths. The load is 200 attendants, 100 locations and
  30 days: **291,600 transactions**, 250,800 ledger entries, 40,800 QRIS payments, 1% flagged.

| Path (cold, one run) | Before index review | After |
|---|---|---|
| Operational dashboard | 93 ms | 79 ms |
| Executive dashboard (month, trend, top 5) | 319 ms | 317 ms (cached 30 s) |
| Reconciliation, one day | 1.0 s | 1.2 s (nightly job) |
| Report revenue by location, 30 days | 142 ms | 174 ms |
| Report transaction detail, one day | 88 ms | 68 ms |
| Admin transaction list (location, 25 rows + totals) | 7 ms | 7 ms |
| **Movement check** (on every transaction creation) | sorted all 1,458 rows of the attendant | **index scan, 0.08 ms** |
| **Collect anomalies**, first run / re-run | 718 / 131 ms | **71 / 5 ms** |

### Database index review
- `EXPLAIN ANALYZE` of the admin list, the movement check and the collector.
- New migration `2026_09_26_100000_add_performance_indexes`:
  - `parking_transactions (attendant_id, transaction_time_device DESC)`, for the movement check;
  - partial indexes on flagged transactions and shifts (the collector);
  - `payments (created_at)` (dashboard).

### Security review
- [docs/operations/security-review.md](../operations/security-review.md): the §33 checklist with
  evidence, the fixes below, and the residual risks.
- **Security headers:** new `SecurityHeaders` middleware on the admin web.
  - Content-Security-Policy: `script-src 'self'`, no inline scripts, `object-src 'none'`,
    `frame-ancestors 'none'`, images limited to self plus the configured map tile host.
  - HSTS on HTTPS; nosniff; Referrer-Policy; Permissions-Policy.
  - Checked against the built page: only same-origin scripts.
- **Production boot guard** `ProductionGuard`: the app refuses to boot in production with
  `APP_DEBUG=true`, a non-https `APP_URL`, or a database other than PostgreSQL. This joins the
  existing fake-gateway guard.
- **Default DB connection is now `pgsql`.** Found during the image smoke test: Laravel's
  default was `sqlite`, so a production config that forgot `DB_CONNECTION` would have silently
  used a different database.
- **Database role separation** `docker/postgres/production-roles.sql` (idempotent):
  - `pati_owner` handles migrations only;
  - `pati_app` (runtime) has no DDL, no TRUNCATE, no UPDATE/DELETE on append-only tables, and no
    DELETE on financial tables;
  - it cannot disable or drop triggers because it does not own them.
- `composer audit` and `npm audit`: no known vulnerabilities.

### Queue failure handling
- `ops:queue-health` runs every 5 minutes. It writes an error log (the alerting hook) on failed
  jobs in the window, a backlog above 500, or an unreadable queue.
- Failed-job inspection and retry are documented. Exports fail visibly with a reason
  (`tries = 1`). The worker already runs with `--tries=3 --backoff=10 --max-time=3600`.

### Backup and restore documentation
- [docs/operations/backup-restore.md](../operations/backup-restore.md) covers what to back up
  (not Redis), daily `pg_dump`, recommended WAL/PITR, restore steps, the monthly restore test,
  and private files.

### Observability
- `docs/architecture/observability.md` has two new sections: the table of scheduled integrity
  checks and how their error logs are used for alerting, and the performance baseline.

### Deployment documentation and production image
- [docs/operations/deployment.md](../operations/deployment.md) covers:
  - components (exactly one scheduler);
  - production environment variables;
  - first installation with the roles script;
  - the release procedure;
  - rollback (fix forward; restore instead of `migrate:rollback` on financial data);
  - operations;
  - the §54 checklist.
- `docker/php/Dockerfile` has a new **`prod` target**:
  - multi-stage build (assets, vendor without dev packages);
  - code baked in, **runs as `www-data`**;
  - production php.ini, opcache without timestamp checks, FPM pool tuning;
  - no `.env`, no tests, no dev package cache.

  `.dockerignore` keeps secrets and local artefacts out of the context.

### Android release hardening
- A release build **fails** unless `-PpatiApiBaseUrlRelease` is a real `https://` URL (the
  placeholder is `.invalid`).
- Release lint found a real issue: play-services pulled in an old Fragment version, which breaks
  `registerForActivityResult`. Fixed with an explicit `fragment-ktx 1.8.5`. The unsigned release
  APK now builds.

## FILES CHANGED

Backend:
- new:
  - `app/Http/Middleware/SecurityHeaders.php`, `app/Support/Security/ProductionGuard.php`,
    `app/Support/Queue/QueueHealthCheck.php`
  - `database/migrations/2026_09_26_100000_add_performance_indexes.php`
  - `tests/Performance/benchmark.php`
  - `tests/Feature/Security/SecurityHeadersTest.php`, `tests/Unit/ProductionGuardTest.php`
- changed: `bootstrap/app.php` (middleware), `app/Providers/AppServiceProvider.php` (guard),
  `routes/console.php` (queue health + schedule), `config/database.php` (default pgsql)

Infrastructure:
- `docker/php/Dockerfile` (prod target), new `.dockerignore`, new `docker/postgres/production-roles.sql`

Android:
- `app/build.gradle.kts` (release URL guard, fragment dependency), `gradle/libs.versions.toml`

Docs:
- new: `docs/operations/{deployment, backup-restore, security-review}.md`, this report
- updated: `docs/architecture/observability.md`, `README.md`

## DATABASE CHANGES

- `2026_09_26_100000_add_performance_indexes`: four indexes, reversible, `IF NOT EXISTS`.
- Production role script (not a migration).

## API

No changes. Admin web responses now carry the security headers.

## TESTS AND VERIFICATION

- Backend `composer check`: Pint PASS (362 files), Larastan level 8 OK, **419 passed (2389
  assertions)**. Phase 11 adds 4 tests (security headers ×3, production guard).
- Frontend build passes.
- Android: `testDebugUnitTest assembleDebug` → 24 tests, 0 failures (3 opt-in e2e tests skipped
  without backend variables; all passed in Phases 5–7). `assembleRelease` with a real URL →
  unsigned APK; with the placeholder → the build fails as intended.
- **DB roles, verified on a scratch database:**
  - migrations run as `pati_owner`;
  - as `pati_app`: SELECT and INSERT work;
  - refused: UPDATE of the audit log, DELETE of the ledger or transactions, TRUNCATE, DISABLE
    TRIGGER, DROP TRIGGER, CREATE TABLE;
  - all seven scheduled commands run successfully as `pati_app`.
- **Backup and restore, verified on the dev database:**
  - `pg_dump -Fc` (358 KB), then `pg_restore --exit-on-error`;
  - the balances match the ledger, reconciliation shows 0 mismatches, and the row counts match;
  - all 25 guard triggers are active in the restored database (UPDATE of the ledger refused).
- **Production image, smoke-tested:**
  - runs as `www-data`, with no `.env` and no dev packages;
  - refuses to boot with the fake gateway or with debug in production;
  - with a valid config it connects to PostgreSQL, runs `cash:verify-balances` and
    reconciliation, and the `php-fpm -t` config test passes.

Issues found and fixed during the phase:
1. SQLite as the default DB connection (above).
2. The dev `bootstrap/cache/*.php` package cache was copied into the image and referenced dev
   packages (Pail). It is now excluded and removed.
3. The Android Fragment version (above).
4. The roles script failed on its first run before migrations. The REVOKEs are now conditional,
   and role creation is idempotent.
5. Benchmark seeding had a collision in its synthetic ID numbers (the DB unique constraint caught it).

## STATIC ANALYSIS

Pint PASS, Larastan level 8 OK, architecture tests pass, TypeScript build passes, Android release
lint passes.

## SECURITY NOTES

See [security-review.md](../operations/security-review.md). Main residual risks for the owner:
- no second factor for staff;
- no Midtrans IP allowlist (the signature is enforced);
- no independent penetration test yet;
- the data retention period is not defined.

## KNOWN LIMITATIONS

- The test suite takes 8–15 minutes on this Windows host (Docker bind-mount I/O, about 1.3 s
  per test for every test). On Linux CI it should be several times faster.
- Android R8 minification stays off until a release build has been tested on a real device.
- The benchmark measures single-user query times, not concurrent load. A load test (for
  example k6 against login, transaction and sync) is recommended on the target infrastructure.

## DEVIATIONS FROM APPROVED PLAN

None.

## BLOCKERS

None for development. Before production (owner):
1. The owner items in master doc §62: official tariffs, locations, number formats, settlement
   policy, report formats, retention.
2. A Midtrans sandbox key for the first real sandbox run, then written approval for production.
3. Confirmation of: `payments.refund_record` → Finance (Phase 6), the thresholds (movement,
   QR expiry), and 2FA for staff.
4. The production infrastructure: domain and TLS, managed PostgreSQL with PITR, and log/alert
   routing.

## NEXT RECOMMENDED STEP

All master doc phases (0–11) are implemented. The recommendations are:
1. an owner review of all phase reports;
2. user acceptance testing with a pilot location on real devices (including the Midtrans sandbox);
3. then a production readiness review against the checklist in deployment.md.
