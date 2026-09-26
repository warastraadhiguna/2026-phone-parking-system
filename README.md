# Sistem Perparkiran Kabupaten Pati

Parking revenue control system for Kabupaten Pati (`pati-parking-platform`).
It connects field parking activity, CASH and QRIS payments, cash deposits, reconciliation,
monitoring and audit in one central system.

| Part | Location | Stack |
|---|---|---|
| Backend API + admin control center | `backend/` | Laravel 13 (modular monolith), PostgreSQL 17, Redis 7, Inertia + React + TypeScript |
| Android app for attendants (juru parkir) | `android/` ([README](android/README.md)) | Kotlin, Compose, Room, WorkManager |
| Documentation | `docs/` | Master requirements, architecture, ADRs, API contracts, operations, phase reports |
| Local environment | `docker/`, `docker-compose.yml` | Docker Compose |

Start with [docs/MASTER_SYSTEM_DOCUMENTATION.md](docs/MASTER_SYSTEM_DOCUMENTATION.md), then
[docs/architecture/overview.md](docs/architecture/overview.md) and [docs/decisions/](docs/decisions/).

## Local development

Requirements: Docker (Desktop) with Compose v2. Nothing else needs to be installed on the host.

```sh
# 1. Configure (local defaults only, no real secrets)
cp backend/.env.example backend/.env

# 2. Build and start: app (php-fpm), nginx, postgres, redis, queue, scheduler
docker compose up -d --build

# 3. Install dependencies, generate the app key, migrate
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
docker compose run --rm node npm install
docker compose run --rm node npm run build
```

Open <http://localhost:8080> (Control Center login) and <http://localhost:8080/api/v1/health/ready>.

### Accounts

```sh
# Local demo accounts, one per role (usernames: superadmin, dishub, operator, keuangan,
# supervisor, auditor, pimpinan; attendant jp-000001 for the app). Password: DEV_SEED_PASSWORD in backend/.env
docker compose exec app php artisan db:seed

# Real installations: create the first Super Admin (no default credentials exist)
docker compose exec app php artisan identity:create-super-admin admin.pati "Nama Lengkap"
```

`php artisan identity:sync-roles` applies the role/permission matrix. `db:seed` and
`identity:create-super-admin` run it automatically. Run it on every deploy.

Frontend with hot reload: `docker compose --profile tools up node`.

### Services and ports

| Service | Purpose | Host port |
|---|---|---|
| `nginx` | HTTP entry point | 8080 (`APP_PORT`) |
| `app` | PHP-FPM (Laravel) | – |
| `queue` | `php artisan queue:work redis` | – |
| `scheduler` | `php artisan schedule:work` | – |
| `postgres` | PostgreSQL 17 (`pati_parking`, test DB `pati_parking_test`) | 5433 (`POSTGRES_PORT`) |
| `redis` | Redis 7.4 (AOF enabled) | 6380 (`REDIS_PORT`) |
| `node` | Vite / TypeScript tooling (profile `tools`) | 5173 |

### Quality checks

```sh
docker compose exec app composer test      # Pest: unit, feature (real PostgreSQL), architecture
docker compose exec app composer lint      # Laravel Pint (check only)
docker compose exec app composer analyse   # Larastan level 8
docker compose exec app composer check     # all of the above
docker compose run --rm node npm run build # TypeScript typecheck + Vite build
```

### Operational commands

```sh
docker compose exec app php artisan ops:queue-heartbeat   # verify a queue worker processes jobs
docker compose exec app php artisan schedule:list         # scheduled tasks
docker compose exec app php artisan ops:queue-health      # failed jobs / backlog
docker compose exec app php artisan payments:fake-simulate <payment_uuid> settlement   # dev: simulate a QRIS payment
```

## Production

- [docs/operations/deployment.md](docs/operations/deployment.md): image, configuration, database roles, release and rollback, checklist.
- [docs/operations/backup-restore.md](docs/operations/backup-restore.md): backups and a tested restore procedure.
- [docs/operations/security-review.md](docs/operations/security-review.md): security review and residual risks.
- Performance baseline: `tests/Performance/benchmark.php` (see [observability.md](docs/architecture/observability.md#6-performance-baseline)).

## Working agreement

Development proceeds phase by phase (master doc §50). Each phase ends with a report in
[docs/implementation-reports/](docs/implementation-reports/) and waits for approval before the
next phase starts. See [CLAUDE.md](CLAUDE.md) for the implementation rules.
