# ADR-0001: Modular monolith in one Laravel application

- Status: Accepted
- Date: 2026-09-25

## Context

The system must be correct, auditable and maintainable by a small team, and must support
thousands of registered attendants. The master document (§5, §7, §36, §40) rules out
microservices, Kubernetes, Kafka and event sourcing for the MVP. It still asks for clear
domain boundaries.

## Decision

- Build **one Laravel application** (`backend/`) serving the `/api/v1` REST API, the Inertia
  admin control center, queue workers and the scheduler.
- Organise business code as **17 modules** under `app/Domain/<Module>/` (master doc §7).
  Each has a public surface (`Actions`, `Contracts`, `Data`, `Enums`, `Events`, `Exceptions`),
  models, and a private `Internal/` namespace.
- A module changes another module's data only through that module's Actions/Contracts.
  Cross-module reads through Eloquent relations are allowed.
- Enforce the boundaries that can be checked mechanically with Pest architecture tests
  (`tests/Arch`). Review the rest in code review.
- No separate packages, services, message brokers or per-module databases.
- Keep the app stateless (sessions, cache and locks in Redis; files in configured storage)
  so it scales horizontally behind a load balancer (§40).

## Consequences

- One deployment, one database transaction boundary, and simple local development.
  Cross-module financial writes can be atomic.
- Boundary discipline depends partly on review. Architecture tests catch the obvious violations.
- Extracting a module later would still be possible, because writes already go through Actions.
- Rejected: microservices (operational cost, distributed transactions for money); Laravel
  packages per module (ceremony without benefit at this size).
