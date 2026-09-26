# Architecture Decision Records

An ADR records a decision that is hard to reverse. Where an ADR is more specific than the
master document, the ADR wins. Changing an accepted ADR requires owner approval and a new ADR
that supersedes it.

| # | Title | Status |
|---|---|---|
| [0001](0001-modular-monolith.md) | Modular monolith in one Laravel application | Accepted |
| [0002](0002-postgresql-source-of-truth.md) | PostgreSQL as the single source of truth | Accepted |
| [0003](0003-redis-responsibilities.md) | Redis responsibilities (and non-responsibilities) | Accepted |
| [0004](0004-admin-web-inertia-react.md) | Admin web: Laravel + Inertia.js + React + TypeScript | Accepted |
| [0005](0005-mobile-authentication.md) | Mobile authentication and device approval | Accepted |
| [0006](0006-payment-abstraction.md) | Payment abstraction, webhooks and refunds | Accepted |
| [0007](0007-financial-ledger-immutability.md) | Financial ledger and audit immutability | Accepted |
| [0008](0008-offline-operation-policy.md) | Offline operation: shifts, cash transactions, tariff mismatch | Accepted |
| [0009](0009-health-endpoints.md) | Liveness and readiness endpoints | Accepted |
| [0010](0010-master-data-rules.md) | Master data rules (locations, attendants, devices, assignments, tariffs) | Accepted |
| [0011](0011-cash-settlement.md) | Cash settlement and outstanding cash | Accepted |
| [0012](0012-reconciliation.md) | Daily reconciliation | Accepted |
| [0013](0013-review-queue.md) | Anomaly signals and the review queue | Accepted |
| [0014](0014-dashboards-and-reports.md) | Dashboards, reports and exports | Accepted |

Template: Context → Decision → Consequences. Date format YYYY-MM-DD.
