# Backup & Restore

Audience: whoever operates the production database. Master doc §54 requires a database backup
**and a tested restore** before production.

## What must be backed up

| Data | Where | Criticality |
|---|---|---|
| PostgreSQL database | all financial records, audit trail, settings | **Critical.** The single source of truth (ADR-0002) |
| Private files | `backend/storage/app/private` (attendant photos, settlement proof photos, report exports) | Important (photos and proofs); exports can be regenerated |
| Redis | cache, queue, sessions, locks | **Not** backed up. Holds no official data (ADR-0003). Losing it logs users out and drops queued jobs, which can be re-queued |
| Secrets (`.env`) | secret manager / deployment system | Critical, but kept **outside** backups of data |

## PostgreSQL

### Daily logical backup (minimum)

Run from a host that can reach the database, with a role that can read every table (the owner
role):

```sh
PGPASSWORD=… pg_dump --host=<db-host> --username=<owner> --format=custom --compress=9 \
  --file=pati_parking_$(date +%Y%m%d_%H%M).dump pati_parking
```

- Keep at least 30 daily and 12 monthly copies **off the database server**, encrypted at rest.
- `--format=custom` restores tables, triggers, functions and sequences. That includes the
  append-only and immutability triggers, which the application relies on.

### Continuous archiving (recommended for production)

Enable WAL archiving (`archive_mode = on`, `archive_command` to object storage) or use a
managed PostgreSQL with point-in-time recovery. Target: RPO ≤ 15 minutes, RTO ≤ 4 hours
(**owner to confirm**).

### Restore

1. Create an empty database with the same owner role:
   `createdb -O <owner> pati_parking_restore`.
2. Restore:
   ```sh
   pg_restore --host=<db-host> --username=<owner> --dbname=pati_parking_restore --jobs=4 --exit-on-error pati_parking_YYYYMMDD_HHMM.dump
   ```
   `pg_restore` creates triggers and constraints in the *post-data* section, after the rows are
   loaded. No guard fires during the restore, so no bypass is needed, and all guards are active
   again afterwards. Do not use `--section=data` alone on a database that already has the triggers.
3. Point a **staging** copy of the application at the restored database and verify it (next
   section) before switching production.

### Restore test (monthly, and before go-live)

On the restored database:

```sh
php artisan migrate:status                 # every migration "Ran"; none pending
php artisan cash:verify-balances           # derived balances == ledger
php artisan reconciliation:run <yesterday> # 0 ERROR mismatches
php artisan identity:sync-roles            # role matrix present
```

Then compare row counts of `parking_transactions`, `payments`, `cash_ledger_entries`,
`cash_settlements` and `audit_logs` with the source at backup time. Record the result (date,
backup file, duration, outcome) in the operations log.

## Private files

Sync `storage/app/private` daily to object storage with versioning, for example
`rclone sync --backup-dir`. Proof photos are referenced by path from `cash_settlements.proof_path`.
Restore them to the same relative paths.

## Local development

```sh
docker compose exec postgres pg_dump -U pati -Fc pati_parking > backup.dump
docker compose exec -T postgres pg_restore -U pati -d pati_parking_restore --exit-on-error < backup.dump
```
