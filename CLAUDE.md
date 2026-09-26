# CLAUDE.md — Sistem Perparkiran Kabupaten Pati

Parking **revenue control** system: every parking transaction must be traceable to an attendant,
location, shift, device, tariff, payment and audit trail, and reconcilable centrally.

## Read before coding

1. `docs/MASTER_SYSTEM_DOCUMENTATION.md` — baseline requirements.
2. `docs/decisions/` — approved ADRs. They override the master doc where they are more specific.
3. `docs/architecture/overview.md` and `docs/coding-conventions.md`.
4. The latest file in `docs/implementation-reports/` — which phase is approved and what is done.

## Rules

1. Work only on the currently approved phase. Stop after the phase report and wait for approval.
2. No microservices, Kubernetes, Kafka, event sourcing, separate payment service, or customer app.
3. Stack is fixed: Laravel modular monolith, PostgreSQL, Redis, Inertia + React + TypeScript (admin, inside `backend/`), Kotlin + Room + WorkManager (Android), REST `/api/v1`.
4. Do not change financial state transitions without approval. PAID payments never move backwards; a voided transaction keeps its PAID payment, and refunds are separate records.
5. Never hardcode tariffs, secrets, merchant credentials, base URLs, API keys, or policy thresholds (use system settings / config).
6. Payment provider code lives only in the Payment module, behind `PaymentGatewayInterface`. `FakePaymentGateway` must never be active in production.
7. Transaction and shift creation/sync must be idempotent (UUID-based).
8. `cash_ledger_entries` and `audit_logs` are append-only (DB triggers). The cash balance table is derived and rebuildable from the ledger.
9. CASH must work offline; QRIS status is only ever set from verified provider confirmation.
10. Money is integer rupiah (`bigint`), never float.
11. Every critical business rule gets a test, against real PostgreSQL (never SQLite).
12. Modules change each other's data only through the owning module's public Actions/Contracts.
13. End each phase with an implementation report in `docs/implementation-reports/`.
14. The role → permission matrix lives in `App\Domain\Identity\Enums\Role::permissions()`; changing it needs owner approval. Write audit records only via `RecordAuditEvent`, inside the same DB transaction as the change.

## Commands (run from repo root)

```sh
docker compose up -d                                   # start stack
docker compose exec app composer test                  # Pest (PostgreSQL test DB)
docker compose exec app composer lint                  # Pint --test
docker compose exec app composer analyse               # Larastan
docker compose exec app composer check                 # all three
docker compose run --rm node npm run build             # typecheck + Vite build
docker compose exec app php artisan identity:sync-roles # apply role matrix (every deploy)
docker compose exec app php artisan db:seed            # local demo accounts
```
