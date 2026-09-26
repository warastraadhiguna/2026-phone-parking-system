# Deployment

Audience: whoever deploys and operates the production system. Baseline: master doc §55 (Linux,
Nginx, PHP-FPM, PostgreSQL, Redis, supervised queue worker; containers allowed). Pre-production
checklist: §54 and the checklist at the end of this document.

## 1. Components

| Component | Runs | Notes |
|---|---|---|
| `app` | `pati-parking/php:<version>` (target `prod`), `php-fpm` as `www-data` | Stateless; can scale horizontally behind the load balancer |
| `queue` | same image, `php artisan queue:work redis --tries=3 --backoff=10 --max-time=3600` | At least 1 per node, supervised (restart on exit) |
| `scheduler` | same image, `php artisan schedule:work` | **Exactly one** in the whole system (tasks also use `onOneServer`) |
| `nginx` | `nginx:1.27`, config like `docker/nginx/default.conf` | TLS terminates here or at the load balancer |
| PostgreSQL 17 | managed service or dedicated host | Source of truth; backups, [backup-restore.md](backup-restore.md) |
| Redis 7 | managed or dedicated, AOF persistence on | Cache, queue, sessions, locks; not a source of truth |

Build the image (CI):

```sh
docker build -f docker/php/Dockerfile --target prod -t pati-parking/php:$(git describe --tags) .
```

The image contains code, `vendor` (no dev packages) and built assets. It contains **no**
`.env` or secrets.

## 2. Configuration (environment)

Everything comes from environment variables or a secret manager, never from the image or the
repository. The minimum for production:

| Variable | Value |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` (the app refuses to boot in production with `true`, or with a non-https `APP_URL`) |
| `APP_KEY` | from the secret manager (`php artisan key:generate --show`, once) |
| `APP_URL` | `https://<domain>` |
| `DB_CONNECTION` | `pgsql` (also the default; the app refuses to boot in production with any other driver) |
| `DB_HOST`, `DB_DATABASE`, `DB_USERNAME=pati_app`, `DB_PASSWORD` | runtime role (§4) |
| `REDIS_HOST`, `REDIS_PASSWORD` | |
| `SESSION_SECURE_COOKIE` | `true` |
| `TRUSTED_PROXIES` | IP range of the load balancer only |
| `PAYMENT_GATEWAY` | `midtrans` (the app refuses to boot with `fake` in production) |
| `MIDTRANS_ENVIRONMENT` | `sandbox` until the owner approves production (§54) |
| `MIDTRANS_SERVER_KEY` | from the secret manager |
| `MIDTRANS_PRODUCTION_APPROVED` | `true` **only** after written owner approval, together with `MIDTRANS_ENVIRONMENT=production` |
| `LOG_CHANNEL` | `structured` (JSON to stderr, collected by the platform) |
| `REPORTING_MAP_TILE_URL` | a tile service with a usage policy that fits production, or empty |

The Android release build gets its API URL at build time: `-PpatiApiBaseUrlRelease=https://<domain>/`.

## 3. First installation

1. Create the database roles with `docker/postgres/production-roles.sql` (edit the passwords
   from the secret manager).
2. Run the migrations as the **owner** role:
   `DB_USERNAME=pati_owner DB_PASSWORD=… php artisan migrate --force`.
3. Re-run the `REVOKE` block of `production-roles.sql`, because it applies to tables that now
   exist.
4. `php artisan identity:sync-roles`.
5. Create the first Super Admin: `php artisan identity:create-super-admin <username> "<name>"`.
   The password is typed interactively and never passed on the command line.
6. Start `app`, `queue`, `scheduler` and `nginx`, then check `/api/v1/health/ready`.
7. Configure the Midtrans notification URL:
   `https://<domain>/api/v1/payments/webhooks/midtrans`.

## 4. Database roles

`pati_owner` owns the schema and is used only for migrations. `pati_app` is the runtime role:
- DML only;
- no TRUNCATE;
- no UPDATE/DELETE on the append-only tables;
- no DELETE on financial tables;
- no DDL.

The triggers enforce the same rules for every role. Because `pati_app` does not own them, it
cannot drop or disable them. There is no hidden bypass (ADR-0007).

## 5. Release procedure (every deploy)

1. Back up the database (`pg_dump`, [backup-restore.md](backup-restore.md)) and note the file name.
2. Enable maintenance mode for the admin web only if a migration locks large tables
   (`php artisan down --secret=…`). The mobile app keeps working offline, and its queue syncs
   later.
3. Run migrations as `pati_owner`: `php artisan migrate --force`.
4. Re-run the `REVOKE` block if the release added append-only or financial tables. The release
   notes say so.
5. Run `php artisan identity:sync-roles` (the role matrix is code).
6. Roll the `app`, `queue` and `scheduler` containers to the new image. Queue workers finish
   their current job (`--max-time`, SIGTERM).
7. Run `php artisan up` and check `/api/v1/health/ready`. Smoke test: log in to the admin web and
   open the dashboard.

### Rollback

- **Code only** (no migration in the release): redeploy the previous image.
- **With migrations:** every migration has a `down()`. However, rolling back tables that
  already hold new financial rows would lose data.
  - Preferred: fix forward.
  - If rollback is unavoidable: stop writes (maintenance mode), restore the backup from step 1
    into a new database, point the app at it, and investigate. Never run `migrate:rollback` on
    production financial tables that contain data.

## 6. Operations

| Task | Where |
|---|---|
| Health | `/api/v1/health/live` (process), `/api/v1/health/ready` (PostgreSQL + Redis) |
| Logs | JSON on stderr, with `request_id` in every line ([observability.md](../architecture/observability.md)) |
| Scheduled checks that raise error logs | `cash:verify-balances` 01:00, `reconciliation:run` 01:30, `ops:queue-health` every 5 min, `payments:check-pending` every minute |
| Failed jobs | `php artisan queue:failed`; retry with `queue:retry <uuid>` after fixing the cause; pruned after 30 days |
| Alerting | Route `level >= error` log lines to on-call (email/Telegram/pager) in the log platform |
| Log rotation | Handled by the container platform (stderr); no log files in the image |

## 7. Pre-production checklist (§54)

- [ ] Separate production environment and credentials (database, Redis, Midtrans, APP_KEY)
- [ ] HTTPS only (HSTS at the edge); `SESSION_SECURE_COOKIE=true`
- [ ] Database backup running **and a restore test performed** ([backup-restore.md](backup-restore.md))
- [ ] Redis persistence (AOF) reviewed; `maxmemory-policy noeviction` for the queue database
- [ ] Queue worker and scheduler supervised (one scheduler only)
- [ ] Log collection, rotation and alerting on error logs
- [ ] Secrets in a secret manager; none in the repository or image
- [ ] Migration plan and rollback plan for the release (§5)
- [ ] Midtrans stays sandbox until the owner approves production **in writing**
- [ ] Owner items from master doc §62 answered (tariffs, locations, formats, …)
