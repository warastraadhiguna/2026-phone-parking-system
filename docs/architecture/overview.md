# Architecture Overview

Status: approved baseline (Phase 0). Requirements: [MASTER_SYSTEM_DOCUMENTATION.md](../MASTER_SYSTEM_DOCUMENTATION.md).
Decisions: [../decisions/](../decisions/). The Indonesian proposal that led to these decisions,
updated with the approved adjustments, is [Proposal_Arsitektur_Phase0.docx](Proposal_Arsitektur_Phase0.docx).

## 1. System purpose

A **parking revenue control system**. Every parking transaction must have an identity, location,
attendant, tariff, payment and audit trail that can be reconciled centrally.

Priority order: correctness → auditability → data integrity → operational reliability →
security → maintainability → performance → advanced features.

## 2. Runtime view

```text
Android app (Kotlin, Room, WorkManager) ── HTTPS REST /api/v1 (access + refresh token) ──┐
Admin browser (Inertia + React + TS) ───── HTTPS, Laravel session + CSRF ────────────────┤
                                                                                          ▼
                              nginx ──► Laravel (PHP-FPM, stateless) × N
                                            │                 │
                                      PostgreSQL           Redis
                                   (source of truth)   (cache, queue, sessions,
                                            ▲            rate limit, locks)
                                            │
                              queue worker(s) + scheduler (same Laravel codebase)
                                            │
                              Payment module ──► PaymentGatewayInterface ──► Midtrans (QRIS)
                                            ▲
                              Midtrans webhook ─┘ (verified, idempotent)
```

- One Laravel application serves the mobile API (`routes/api.php`, `/api/v1`) and the admin
  control center (`routes/web.php`, Inertia pages). There is no separate admin SPA or auth system.
- The app keeps no local state between requests, so instances can be added behind a load balancer.
- PostgreSQL holds every official financial fact. Redis never does ([ADR-0003](../decisions/0003-redis-responsibilities.md)).

## 3. Repository layout

```text
pati-parking/
├── backend/                       Laravel application
│   ├── app/
│   │   ├── Domain/<Module>/       business modules (see §4)
│   │   ├── Http/Api/V1/<Module>/  mobile/machine API controllers, requests, resources
│   │   ├── Http/Admin/<Module>/   admin (Inertia) controllers
│   │   ├── Http/Middleware/       cross-cutting HTTP middleware
│   │   └── Support/               framework-level building blocks (no business rules)
│   ├── resources/js/              admin frontend: Pages/, Components/, Layouts/, hooks/, types/
│   ├── database/migrations/       PostgreSQL schema (reversible where possible)
│   ├── routes/                    api.php, web.php, console.php (schedule + ops commands)
│   └── tests/                     Unit/, Feature/ (real PostgreSQL), Arch/
├── android/                       Android attendant app, offline-first (see android/README.md)
├── docker/                        php, nginx, postgres init
├── docs/                          requirements, architecture, API, database, ADRs, reports
├── docker-compose.yml
├── README.md
└── CLAUDE.md
```

## 4. Module boundaries

Modules live in `app/Domain/<Module>/`. The seventeen modules are listed in the master doc §7.
Each module's `README.md` states what it owns and when it is built.

| Module | Owns |
|---|---|
| Identity | users, roles/permissions, authentication, mobile refresh tokens |
| ParkingLocation | locations, coordinates, geofence radius |
| ParkingAttendant | attendant registry and status |
| Device | device registration and approval (`PENDING_APPROVAL → ACTIVE`, `REVOKED`, `LOST`) |
| Assignment | attendant ↔ location assignments with validity periods |
| Shift | shifts, including offline-created shifts |
| Tariff | versioned tariffs, tariff resolution at a point in time |
| ParkingTransaction | transactions, tariff snapshot, idempotent create/sync |
| Payment | payments, gateway abstraction, webhooks, manual refunds/adjustments |
| CashLedger | append-only cash ledger (source of truth) + derived balance |
| CashSettlement | cash deposit submission and verification |
| Reconciliation | cross-checks and mismatch reports |
| Audit | immutable audit trail |
| FraudReview | rule-based anomaly flags and review queue |
| Reporting | read models, reports, queued exports |
| Notification | outbound notifications |
| SystemConfiguration | runtime policy settings (e.g. `offline_transaction_warning_hours`) |

### Standard module layout

```text
app/Domain/<Module>/
├── Actions/      public: use cases other modules and controllers may call (the only write entry points)
├── Contracts/    public: interfaces offered to other modules (e.g. PaymentGatewayInterface)
├── Data/         public: immutable DTOs crossing the module boundary
├── Enums/        public: statuses and types, with allowed-transition rules
├── Events/       public: domain events other modules may listen to
├── Exceptions/   public: module exceptions (usually extending App\Support\Errors\ApiException)
├── Models/       Eloquent models (other modules may read and relate, never write)
├── Policies/     authorization
└── Internal/     private implementation details (provider adapters, calculators, queries)
```

### Rules

1. **Writes go through the owner.** A module changes another module's data only by calling
   that module's `Actions` or `Contracts`. It never calls `save()`, `update()` or `delete()` on
   another module's models, and never writes that module's tables with the query builder.
2. **Reads are allowed.** Eloquent relations and read queries across modules are fine. They
   are not worth an extra abstraction layer in a monolith.
3. **`Internal/` is private.** Only the owning module may use it. Architecture tests enforce this.
4. **Domain code is HTTP-agnostic.** `App\Domain` must not use `App\Http`, `Request` or Inertia.
   Controllers translate HTTP into Action calls.
5. **`App\Support` holds no business rules** and must not depend on `App\Domain`.
6. **Provider details stay in Payment.** Midtrans SDK/adapters live in
   `App\Domain\Payment\Internal\Gateways` and are used nowhere else. ParkingTransaction never
   sees credentials, signatures, endpoints or provider payloads ([ADR-0006](../decisions/0006-payment-abstraction.md)).
7. **Cross-module transactions:** when one use case touches several modules (for example, a cash
   transaction writes a ledger entry), the calling Action opens the DB transaction and calls
   the other module's Action inside it.

Rules 3–6 are checked by `tests/Arch/ArchitectureTest.php`. Rules 1–2 and 7 are checked in code review.
They are deliberately not split into packages or services ([ADR-0001](../decisions/0001-modular-monolith.md)).

## 5. Cross-cutting foundations (Phase 0)

| Concern | Where | Doc |
|---|---|---|
| API response envelope | `App\Support\Http\ApiResponse` | [../api/README.md](../api/README.md) |
| Error codes | `App\Support\Errors\ErrorCode`, `ApiException` | [../api/README.md](../api/README.md) |
| Exception → envelope mapping | `App\Support\Http\ApiExceptionRenderer` (wired in `bootstrap/app.php`) | [../api/README.md](../api/README.md) |
| Request / correlation ID | `App\Http\Middleware\AssignRequestId`, `App\Support\RequestId\RequestId` | [observability.md](observability.md) |
| Structured, redacted logs | `App\Support\Logging\*`, channel `structured` | [observability.md](observability.md) |
| Health endpoints | `App\Http\Api\V1\System\HealthController`, `App\Support\Health\*` | [observability.md](observability.md) |
| Append-only tables | `App\Support\Database\AppendOnlyTable` + PG function | [ADR-0007](../decisions/0007-financial-ledger-immutability.md) |
| Queue probe | `ops:queue-heartbeat`, `App\Support\Queue\QueueHeartbeat` | [observability.md](observability.md) |
| Request context for domain code (client IP, device) | `App\Support\RequestContext\RequestContext` (hidden Laravel Context), set by `CaptureRequestContext` / `EnsureMobileAttendant` | this document |
| Audit trail | `App\Domain\Audit\Actions\RecordAuditEvent` (only writer of `audit_logs`) | [ADR-0007](../decisions/0007-financial-ledger-immutability.md) |
| Authentication & authorization | Identity module; roles defined in code ([roles-and-permissions.md](roles-and-permissions.md)) | [ADR-0004](../decisions/0004-admin-web-inertia-react.md), [ADR-0005](../decisions/0005-mobile-authentication.md) |

## 6. Approved decisions that refine the master document

| Topic | Decision | ADR |
|---|---|---|
| Admin web | Laravel + Inertia.js + React + TypeScript inside `backend/`; session auth + CSRF; no JWT, no Vue, no separate SPA | [0004](../decisions/0004-admin-web-inertia-react.md) |
| Offline shift | Allowed only under six preconditions; idempotent via `shift_uuid`; the shift syncs before its transactions | [0008](../decisions/0008-offline-operation-policy.md) |
| Void of a PAID QRIS transaction | Payment stays `PAID`; refunds are separate `MANUAL_REFUND` records | [0006](../decisions/0006-payment-abstraction.md) |
| Device approval | New status `PENDING_APPROVAL`; one `ACTIVE` device per attendant (DB constraint) | [0005](../decisions/0005-mobile-authentication.md) |
| Thresholds | `offline_transaction_warning_hours = 24`, `max_open_shift_hours = 16` as system settings, not constants | [0008](../decisions/0008-offline-operation-policy.md) |
| Cash truth | `cash_ledger_entries` is the source of truth; `attendant_cash_balances` is derived and rebuildable | [0007](../decisions/0007-financial-ledger-immutability.md) |
| Stale offline tariff | Keep what was charged; store `charged_tariff_amount`, `server_expected_tariff_amount`, difference + `TARIFF_MISMATCH` flag | [0008](../decisions/0008-offline-operation-policy.md) |
| Health | `/api/v1/health/live` and `/api/v1/health/ready` (no plain `/health`) | [0009](../decisions/0009-health-endpoints.md) |
| Transactions & cash | Charged amount preserved + server tariff snapshot; ledger is the cash truth, balance derived and rebuildable; §47 transitions enforced by a DB trigger; voids via supervisor with ledger reversal | [0007](../decisions/0007-financial-ledger-immutability.md), [0008](../decisions/0008-offline-operation-policy.md) |
| Shifts & settings | Idempotent device-created shifts; offline violations become review flags; one open shift per attendant (DB); policy thresholds in `system_settings` | [0008](../decisions/0008-offline-operation-policy.md) |
| Master data | Immutable location code; attendant code = username; one location per attendant per day; four-eyes, non-retroactive, versioned tariffs | [0010](../decisions/0010-master-data-rules.md) |
| QRIS | Transaction WAITING_PAYMENT + payment; provider call outside DB transactions; status only from verified provider answers (webhook/status), forward-only, amount-checked; late PAID flagged; fake gateway impossible in production | [0006](../decisions/0006-payment-abstraction.md) |
| Settlement | Submission moves no money; finance verifies the counted amount → one SETTLEMENT_OUT; never more than held; four eyes; outstanding = ledger balance; summaries per total/shift/day | [0011](../decisions/0011-cash-settlement.md) |
| Reconciliation | Daily immutable snapshot (REPEATABLE READ) from source tables; per attendant and location; ERROR (integrity) vs WARNING (needs action) mismatches; re-run adds history | [0012](../decisions/0012-reconciliation.md) |
| Review queue & audit viewer | Signals stay on their records; an idempotent collector fills `anomaly_reviews`; Supervisor records final findings (never money changes); impossible-movement rule with setting thresholds; read-only audit viewer | [0013](../decisions/0013-review-queue.md) |
| Dashboards & reports | One revenue definition (= reconciliation); dashboards cached 30 s; map with configurable tiles, no tracking; 11 reports, preview + queued CSV/XLSX/PDF, requester-only download, 7-day retention | [0014](../decisions/0014-dashboards-and-reports.md) |
| Offline sync | Android writes to Room + a sync queue first; order shift start → transactions (batch of 50, per-item result) → shift end; rejected items are kept, never dropped | [0008](../decisions/0008-offline-operation-policy.md) |
