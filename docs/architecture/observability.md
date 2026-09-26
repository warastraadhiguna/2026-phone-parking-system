# Observability: Request IDs, Logging, Health

## 1. Request / correlation ID

Every HTTP request gets one ID, used for the whole request path.

1. **nginx** forwards the client's `X-Request-Id`. If the client sent none, nginx uses its own
   `$request_id` (32 hex characters). The ID appears in the JSON access log as `request_id`.
2. **`AssignRequestId`** (the first global middleware) reuses the incoming value when it matches
   `^[A-Za-z0-9._-]{8,128}$`. Otherwise it generates a UUIDv7. This blocks log injection and
   oversized values.
3. The ID is stored in Laravel **Context** (`request_id`). That places it on:
   - every log record, as `extra.request_id`;
   - every queued job dispatched during the request, because Context travels with the job
     (proven by `ops:queue-heartbeat`);
   - every API response body, as `meta.request_id` (success and error);
   - every response, as the `X-Request-Id` header (API and admin web).

The Android app should send its own `X-Request-Id` (for example `<device-short-id>-<uuid>`),
so a field report can be traced from device to nginx to Laravel to the queue.

## 2. Structured logging

- Default channel: `structured` (`LOG_STACK=structured`). Output is one JSON object per line,
  written to `LOG_STRUCTURED_STREAM` (default `php://stderr`, collected by `docker compose logs`).
- Formatter: `App\Support\Logging\RedactingJsonFormatter`, installed by the
  `UseRedactingJsonFormatter` tap.
- Record shape: `message`, `context`, `level`, `level_name`, `channel`, `datetime` (UTC), `extra.request_id`.
- `{placeholder}` interpolation is done by `PsrLogMessageProcessor`.

### Redaction

`App\Support\Logging\SensitiveDataRedactor` runs on `message`, `context` and `extra`, at any
nesting depth, as the last step before output:

- Key matching is case- and separator-insensitive (`api_key` = `X-Api-Key` = `apiKey`).
- **Exact keys:** password, password_confirmation, current/new_password, pin, token,
  access/refresh/id_token, authorization, proxy-authorization, cookie, set-cookie, secret,
  client_secret, api_key, server_key, client_key, private_key, signature, signature_key,
  app_key, credential(s), xsrf/csrf token.
- **Suffixes:** `*password`, `*token`, `*secret`, `*apikey`, `*serverkey`, `*privatekey`
  (so `midtrans_server_key` is caught).
- **Free text:** `Bearer …` and `Basic …` credentials are masked.

Redaction is a safety net, not permission. Rules for code:

- Never log passwords, access or refresh tokens, Midtrans server keys, API secrets, or full
  credential values.
- Do not log full payment provider payloads. Store them in `payment_webhook_events`
  (sanitised) and log only the event and payment IDs (Phase 6).
- Log exception classes rather than messages when a message can contain connection strings
  (see the health checks).

Tests: `tests/Feature/Support/StructuredLoggingTest.php`, `tests/Unit/SensitiveDataRedactorTest.php`.

## 3. Health endpoints

| Endpoint | Meaning | Touches dependencies | Success | Failure |
|---|---|---|---|---|
| `GET /api/v1/health/live` | The PHP process can route and answer a request | **No** | `200` `{"data":{"status":"alive"}}` | No response / 5xx means restart the instance |
| `GET /api/v1/health/ready` | This instance can serve traffic: every check in `config/health.php` passes | PostgreSQL (`select 1`), Redis (`PING`) | `200` `{"data":{"status":"ready","checks":{...}}}` | `503` `SERVICE_UNAVAILABLE` with `error.details.checks` |

- **Liveness never fails because PostgreSQL or Redis is down.** Restarting the app would not fix
  a database outage. Tested in `HealthEndpointsTest`.
- Readiness reports each check as `{"status":"ok|fail","duration_ms":…}` and never includes
  hosts, usernames or exception messages. The failing check is logged at `warning` with its
  exception class and the request ID.
- Both endpoints send `Cache-Control: no-store` and need no authentication. They expose no data.
- There is **no plain `/api/v1/health`** and no Laravel `/up` route. They were removed so each
  health URL has one meaning ([ADR-0009](../decisions/0009-health-endpoints.md)).
- Load balancer and orchestrator guidance: use `live` for restart decisions and `ready` for
  routing decisions. The docker-compose nginx healthcheck uses `live`.

### Timeouts

| Setting | Default | Effect |
|---|---|---|
| `DB_CONNECT_TIMEOUT` | 3 s | PDO connect timeout (all DB connections) |
| `REDIS_TIMEOUT` / `REDIS_READ_TIMEOUT` | 2 s | App Redis connections (which also retry with backoff) |
| `REDIS_HEALTH_TIMEOUT` | 1 s | Dedicated `health` Redis connection: same server, **no retries**, used only by readiness |

Measured locally: with Redis unresponsive, `ready` answers `503` in about 1.3 s for the Redis
check. When the Redis container is removed entirely, the local Docker hostname lookup adds
about 3 s. That comes from the local DNS setup, not the check.

## 4. Queue and scheduler

- Queue: Redis connection, worker `php artisan queue:work redis --tries=3 --backoff=10 --max-time=3600`.
  Failed jobs are stored in PostgreSQL (`failed_jobs`) for investigation.
- Scheduler: `php artisan schedule:work` (dev container). Production uses the same command or a
  cron `schedule:run` (Phase 11). Tasks use `onOneServer()` (lock in Redis) so several
  instances do not double-run them.
- Phase 0 tasks: `prune-failed-jobs`, `prune-job-batches` (daily, 30-day retention).
- Probe: `php artisan ops:queue-heartbeat` dispatches `QueueHeartbeat`. The worker logs
  `Queue heartbeat processed` with the same `request_id`.

## 5. Scheduled integrity checks and alerting (Phase 11)

Each check writes an **error-level** log line when something is wrong. Route `level >= error`
to on-call in the log platform; that is the alerting mechanism (no extra service needed).

| Schedule (WIB) | Command | Alerts when |
|---|---|---|
| every minute | `payments:check-pending` | provider unreachable (warning), status applied from the provider |
| every 5 min | `ops:queue-health` | jobs failed in the last 5 minutes, or backlog > 500, or the queue cannot be read |
| every 5 min | `anomalies:collect` | (never alerts; fills the review queue) |
| hourly | `shifts:flag-overdue` | (flags only) |
| 01:00 | `cash:verify-balances` | derived cash balance ≠ ledger (exit 1) |
| 01:30 | `reconciliation:run` | reconciliation ERROR mismatches (exit 1) |
| 02:00 | `reports:prune-exports` | (housekeeping) |

Failed jobs stay in `failed_jobs` for 30 days: inspect them with `php artisan queue:failed` and
retry with `php artisan queue:retry <uuid>` after fixing the cause. Report exports do not retry
automatically (`tries = 1`): they are marked FAILED with the reason and the user requests again.
`ops:queue-heartbeat` proves end to end that a worker is consuming jobs.

## 6. Performance baseline

`tests/Performance/benchmark.php` seeds a synthetic month into a `*_perf` database and times the
heavy read paths (docs/implementation-reports/phase-11.md has the numbers). Re-run it after
changes to dashboards, reports, reconciliation or transaction creation.
