# Implementation Report — Phase 0

```text
PHASE:
Phase 0 — Repository & Architecture Foundation

STATUS:
DONE
```

Date: 2026-09-25. Environment: Windows 11 + Docker Desktop 29.4 (all commands run inside containers).

## IMPLEMENTED

- Monorepo initialised (`git init`, branch `main`) with `.gitignore`, `.gitattributes` (LF),
  `.editorconfig`, `README.md` and `CLAUDE.md`. Master documentation moved to
  `docs/MASTER_SYSTEM_DOCUMENTATION.md`.
- Docker development environment: `app` (PHP 8.4-FPM), `nginx`, `postgres` (17), `redis`
  (7.4, AOF), `queue` (worker), `scheduler`, plus an optional `node` tooling service.
- Laravel 13.33 in `backend/`. `.env.example` holds no real secrets (local-only DB password,
  labelled as such). PostgreSQL and Redis are configured, with fail-fast timeouts.
- Modular-monolith skeleton: 17 modules under `app/Domain/` (master doc §7), each with a
  README stating what it owns and when it is built.
- Admin frontend baseline: Inertia.js 3 + React 19 + TypeScript (strict) with `Pages/`,
  `Components/`, `Layouts/`, `hooks/` and `types/`, typed shared props, a placeholder `Home`
  page, and Tailwind v4. No business screens.
- API envelope (`ApiResponse`), `ErrorCode` enum (generic codes + all §43 codes),
  `ApiException`, and global API exception mapping (`ApiExceptionRenderer`). Every API error
  uses the envelope and never exposes internals, even with `APP_DEBUG=true`.
- `X-Request-Id` middleware: reuses well-formed IDs and replaces malformed ones. The ID goes
  into Laravel Context, so it reaches logs, queued jobs, response bodies and headers. nginx
  forwards and logs the same ID.
- Structured JSON logging (`structured` channel) with recursive redaction of sensitive keys
  and bearer/basic credentials.
- Health endpoints `GET /api/v1/health/live` and `GET /api/v1/health/ready`, with pluggable
  readiness checks (`config/health.php`) and a fail-fast Redis `health` connection.
- PostgreSQL append-only foundation: `forbid_append_only_mutation()` trigger function plus the
  `AppendOnlyTable::protect()/release()` helper, for audit and ledger tables in later phases.
- Scheduler tasks: `prune-failed-jobs`, `prune-job-batches` (daily). Ops command
  `ops:queue-heartbeat` to prove a worker processes jobs.
- Tooling: Pest 5 (unit, feature, architecture suites), Laravel Pint, Larastan 3 at level 8.
  Composer scripts `test`, `lint`, `format`, `analyse`, `check`.
- Documentation: architecture overview (with module boundary rules), observability
  (request ID, logging, health semantics), API conventions, database conventions and target
  model, coding conventions, 9 ADRs. The Indonesian proposal is updated to Revisi 2 with the
  approved decisions (Livewire replaced by Inertia + React + TypeScript).

## FILES CHANGED

Root:
- `.editorconfig`, `.gitattributes`, `.gitignore`, `README.md`, `CLAUDE.md`, `docker-compose.yml`
- `docker/php/Dockerfile`, `docker/php/conf.d/app.ini`, `docker/nginx/default.conf`,
  `docker/postgres/init/01-create-test-database.sql`

Backend (created from the Laravel 13 skeleton, then changed):
- `bootstrap/app.php`: api routes, `AssignRequestId` (first global middleware), Inertia
  middleware, API exception rendering; Laravel `/up` removed
- `config/database.php` (timeouts, `health` Redis connection), `config/logging.php`
  (`structured` channel), `config/inertia.php` (published; `Pages` path, SSR off, DevTools off),
  `config/health.php` (new), `config/filesystems.php` (type fix)
- `app/Support/Errors/{ErrorCode,ApiException}.php`
- `app/Support/Http/{ApiResponse,ApiExceptionRenderer}.php`
- `app/Support/RequestId/RequestId.php`, `app/Http/Middleware/AssignRequestId.php`
- `app/Support/Logging/{SensitiveDataRedactor,RedactingJsonFormatter,UseRedactingJsonFormatter}.php`
- `app/Support/Health/{HealthCheck,CheckResult,ReadinessProbe}.php`, `app/Support/Health/Checks/{DatabaseCheck,RedisCheck}.php`
- `app/Support/Database/AppendOnlyTable.php`, `app/Support/Queue/QueueHeartbeat.php`
- `app/Http/Api/V1/System/HealthController.php`, `app/Http/Middleware/HandleInertiaRequests.php`
- `app/Domain/<17 modules>/README.md`
- `routes/api.php` (new), `routes/web.php`, `routes/console.php`
- `database/migrations/2026_09_25_000100_create_append_only_guard_function.php` (new);
  `0001_01_01_000001_create_cache_table.php` removed
- `resources/views/app.blade.php`, `resources/css/app.css`,
  `resources/js/{app.tsx, Pages/Home.tsx, Layouts/AdminLayout.tsx, Components/EnvironmentBadge.tsx, hooks/useSharedProps.ts, types/index.ts, types/global.d.ts}`;
  removed `welcome.blade.php`, `app.js`, `vite.config.js`
- `vite.config.ts`, `tsconfig.json`, `package.json`, `package-lock.json`, `composer.json`,
  `composer.lock`, `phpunit.xml`, `phpstan.neon`, `.env.example`, `README.md`
- Tests: `tests/Pest.php`, `tests/Unit/SensitiveDataRedactorTest.php`,
  `tests/Feature/{Admin/InertiaBaselineTest, Database/PostgresFoundationTest, Health/HealthEndpointsTest, Support/ApiErrorEnvelopeTest, Support/RequestIdTest, Support/StructuredLoggingTest}.php`,
  `tests/Arch/ArchitectureTest.php`; skeleton example tests removed
- Removed skeleton `backend/CLAUDE.md` and `backend/AGENTS.md` (Laravel Boost agent prompts),
  replaced by the root `CLAUDE.md`

Docs:
- `docs/MASTER_SYSTEM_DOCUMENTATION.md` (moved, unchanged)
- `docs/architecture/{overview.md, observability.md, Proposal_Arsitektur_Phase0.docx}`
- `docs/api/README.md`, `docs/database/README.md`, `docs/coding-conventions.md`
- `docs/decisions/README.md`, `docs/decisions/0001…0009-*.md`
- `docs/implementation-reports/phase-0.md` (this file)

## DATABASE CHANGES

- Laravel default migrations kept: `users`, `password_reset_tokens`, `sessions`, `jobs`,
  `job_batches`, `failed_jobs`. Identity adapts `users` in Phase 1. Sessions actually run on Redis.
- Removed: `cache` table migration (the cache uses Redis).
- New: `forbid_append_only_mutation()` PL/pgSQL function. It raises SQLSTATE 23001, and
  `down()` drops it. No business tables yet.
- Separate test database `pati_parking_test`, created by the postgres init script.

## INFRASTRUCTURE

| Service | Image | Verified |
|---|---|---|
| app | `pati-parking/php:dev` (PHP 8.4.26-FPM, pdo_pgsql, redis, intl, bcmath, pcntl, opcache) | FPM running; Laravel boots |
| nginx | `nginx:1.27-alpine`, port 8080 | Serves the app; JSON access log with `request_id`; compose healthcheck `healthy` (uses `/health/live`) |
| postgres | `postgres:17-alpine`, port 5433 | `healthy`; migrations ran; readiness `database: ok` |
| redis | `redis:7.4-alpine`, AOF, port 6380 | `healthy`; readiness `redis: ok` |
| queue | same image, `queue:work redis` | Processed `QueueHeartbeat` (`DONE`), log line carried the dispatching `request_id` |
| scheduler | same image, `schedule:work` | Running; `schedule:list` shows both tasks; `schedule:test` ran `queue:prune-failed` → `DONE` |
| node (profile `tools`) | `node:22-alpine` | `npm run build` = `tsc -p .` + Vite build succeed |

## API / HEALTH ENDPOINTS

| Endpoint | Behaviour (verified through nginx) |
|---|---|
| `GET /api/v1/health/live` | `200 {"success":true,"data":{"status":"alive"},"meta":{"request_id":…},"error":null}`. Stayed `200` while Redis was stopped. |
| `GET /api/v1/health/ready` | `200` with `checks.database/redis = ok`. With Redis stopped: `503 SERVICE_UNAVAILABLE`, `details.checks.redis.status = fail`, and no connection details in the body. |
| `GET /` (admin) | `200`, Inertia root with component `Home`, shared props `app.name/environment`, built assets. |

The same `X-Request-Id` (`outage-drill-0001`) appeared in the response, the Laravel warning
log and the nginx access log.

## TESTS

- Command: `docker compose exec app composer test` (runs `pest`, database `pati_parking_test` on PostgreSQL 17)
- Result: **85 passed (264 assertions)**, about 35 s

| Suite | Covers |
|---|---|
| Unit / SensitiveDataRedactor | key variants, free-text bearer/basic, recursion bound |
| Feature / Health | live OK; live unaffected by DB+Redis outage; ready OK; ready 503 on DB down; ready 503 on Redis down; no leak of connection details |
| Feature / RequestId | generated when missing; reused when valid; replaced when malformed (short, newline injection, markup, too long); present on errors and admin web |
| Feature / ApiErrorEnvelope | 404, 405, domain error, 403, other 4xx, unexpected 500 (all envelope + header); validation `details.fields`; no internals even with debug on; JSON without `Accept` header |
| Feature / StructuredLogging | JSON line with `extra.request_id` matching the response; redaction of password, refresh token, Midtrans server key, `X-Api-Key`, Authorization header, bearer text |
| Feature / Inertia baseline | `Home` component (page file must exist), shared props |
| Feature / PostgreSQL foundation | driver is `pgsql` and DB is `pati_parking_test`; append-only triggers reject UPDATE/DELETE/TRUNCATE with SQLSTATE 23001 and data stays unchanged; query builder blocked; explicit `release()` path works |
| Arch | see Static Analysis |

## STATIC ANALYSIS

- **Pint:** `composer lint` → `PASS` (54 files)
- **Larastan:** `composer analyse`, level 8 over `app`, `bootstrap/app.php`, `config`, `database`,
  `routes` → `[OK] No errors`, no baseline file. The first run found 2 real type issues; both were fixed.
- **Architecture tests:** 23 passed. They cover: all 17 modules exist; each module's
  `Internal` is private; `App\Domain` does not use HTTP/Request/Inertia; `App\Support` does
  not use `App\Domain`; Midtrans and payment gateway adapters are used only in Payment; no
  `dd`/`dump`/`ray`/`var_dump`/`print_r`/`phpinfo`; no `env()` in app code.
  **Negative check:** five deliberately planted violations (cross-module `Internal` use,
  `Request` in the domain, Support→Domain, `dd()`, `env()`) were each detected, then removed.
- **TypeScript:** `tsc -p .` (strict, `noUncheckedIndexedAccess`) passes as part of `npm run build`.

## SECURITY NOTES

- No real credentials in the repository. `.env` is ignored. `.env.example` contains only the
  labelled local Docker password `pati_local_dev_only`. A scan of all files to be committed
  found no app keys, Midtrans keys or private keys. Test fixtures use obviously fake values.
- Logs redact passwords, PINs, tokens (access, refresh, CSRF), Authorization/Cookie headers,
  secrets, API/server/private keys and signatures, at any depth.
- API errors never expose exception messages, classes or traces. Readiness responses never
  expose hosts or usernames, and health checks log exception classes only.
- Incoming `X-Request-Id` values are validated, which prevents log injection.
- Admin sessions are encrypted and stored in Redis. CSRF protection comes from Laravel's web
  middleware. Inertia DevTools (which writes request data to disk) is disabled by default.
- nginx: `server_tokens off`, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`;
  only `index.php` executes; dotfiles denied. `.npmrc` sets `ignore-scripts=true`.
- The dev image runs FPM workers as root because of Windows bind-mount ownership. This is
  **dev only** and documented in the Dockerfile. The production image (Phase 11) must run
  unprivileged.
- Append-only triggers are in place. Production DB role separation (runtime role without
  UPDATE/DELETE on protected tables) is documented in ADR-0007 for Phase 11.
- HTTPS, rate limiting and authentication start in Phase 1 and Phase 11. Phase 0 exposes only
  the health endpoints and a placeholder page.

## ARCHITECTURE DECISIONS / ADR

- ADR-0001 Modular monolith in one Laravel application
- ADR-0002 PostgreSQL as the single source of truth (money as bigint rupiah, real-PG tests)
- ADR-0003 Redis responsibilities (queue, cache, sessions, rate limit, locks; never financial truth)
- ADR-0004 Admin web: Laravel + Inertia.js + React + TypeScript, session auth + CSRF (Q1)
- ADR-0005 Mobile authentication (Sanctum access + rotating hashed refresh token) and device approval `PENDING_APPROVAL` (Q4)
- ADR-0006 Payment abstraction, webhook rules, Fake gateway production guard, PAID stays PAID on void + `MANUAL_REFUND` (Q3)
- ADR-0007 Financial ledger and audit immutability, derived rebuildable cash balance, maintenance/backup/restore interaction
- ADR-0008 Offline operation: offline shift preconditions (Q2), configurable thresholds (Q5), tariff mismatch preservation
- ADR-0009 Liveness vs readiness endpoints

## KNOWN LIMITATIONS

- **Dev-only root FPM workers** (see Security Notes). A production Dockerfile target is not
  part of Phase 0.
- With the Redis container removed (not merely unresponsive), readiness takes about 4 s,
  because of phpredis hostname resolution in local Docker. With Redis unresponsive, the
  Redis check fails in about 1.3 s.
- The test suite takes about 35 s, mostly because of Windows bind-mount I/O. It would be faster on Linux or WSL.
- No CI pipeline yet: there is no remote repository.
- No code coverage driver is installed (pcov/xdebug). It can be added when coverage targets are agreed.
- Laravel skeleton defaults kept for Phase 1: `users`, `password_reset_tokens`, and the unused
  `sessions` table. The `laravel/pao` dev dependency (agent-friendly test output) came with the
  skeleton and was kept.
- `APP_LOCALE=en`. The UI language (Indonesian) is decided when the first screens are built.
- **No git commit has been made.** All files are untracked, waiting for your go-ahead.

## DEVIATIONS FROM APPROVED PLAN

- **Health:** only `/health/live` and `/health/ready`. No plain `/api/v1/health`, and Laravel's
  default `/up` was removed, so each URL has one meaning (ADR-0009).
- **Added `BAD_REQUEST` error code** for 4xx statuses without a specific code (for example 413),
  so they are not mislabelled as `VALIDATION_FAILED`.
- **Added `ops:queue-heartbeat` + `QueueHeartbeat` job** (not in the plan) to prove the queue
  worker processes jobs, a Phase 0 acceptance condition. It is kept as an operational probe.
- **Dedicated `health` Redis connection** (no retries, 1 s timeout). Without it, readiness
  took 5.5 s during an outage.
- **Inertia configuration:** Inertia 3 defaults to `resources/js/pages`. It was configured to
  `resources/js/Pages` to match the approved structure. SSR and DevTools are off by default.
- **Removed skeleton items:** `cache` table migration, the Laravel Boost `CLAUDE.md`/`AGENTS.md`
  in `backend/`, SQLite defaults, the composer `setup`/`dev` scripts, and the npm
  `concurrently`/`@laravel/multiplex` packages (all for a non-Docker workflow).
- **Admin sessions stored in Redis** (ADR-0003). This is a use of Redis not listed in master doc §38.
- **Two extra ADRs** beyond the minimum (0008 offline policy, 0009 health) to record the approved Q2/Q5 and health decisions.
- Library versions are newer than in the initial proposal: Laravel 13.33, Inertia 3.3 /
  @inertiajs/react 3.7, React 19.3, TypeScript 7.0, Vite 8, Tailwind 4.3, Pest 5.2,
  Larastan 3.12 (PHPStan 2.2), PHP 8.4, PostgreSQL 17, Redis 7.4.

## BLOCKERS

- None.

## NEXT RECOMMENDED PHASE

Phase 1 — Identity & Access (users, roles/permissions, admin session login, mobile
access/refresh token login, audit of login events, tests).

**Phase 1 has not been started. Waiting for explicit approval.**
