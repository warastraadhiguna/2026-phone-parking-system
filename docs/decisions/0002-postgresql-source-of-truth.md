# ADR-0002: PostgreSQL as the single source of truth

- Status: Accepted
- Date: 2026-09-25

## Context

Official financial figures (transactions, payments, cash, settlements, tariffs, audit) must
live centrally (master doc §3.1) and be protected by the database itself, not only by
application code. Offline devices and caches must never be authoritative.

## Decision

- **PostgreSQL 17** holds every official record. Devices, Redis and caches are never authoritative.
- Protect invariants in the database: foreign keys, `CHECK` constraints, unique and **partial
  unique indexes** (for example one `OPEN` shift per attendant), **row locking**
  (`SELECT … FOR UPDATE`) for state that depends on current state, and **triggers** for
  append-only tables ([ADR-0007](0007-financial-ledger-immutability.md)).
- **Money is `bigint` whole rupiah** (Rp2.000 → `2000`). Never floating point.
- Timestamps are `timestamptz` in UTC. Device time and server time are stored separately.
- **Tests run against real PostgreSQL** (`pati_parking_test`), never SQLite. The behaviours
  above do not exist in SQLite, or behave differently there.
- No PostGIS for the MVP. Geofence distance is a Haversine calculation in PHP.

## Consequences

- Invariants hold even if application code has a bug, or someone writes to the DB directly.
- The test suite needs the Docker `postgres` service. Tests are slower than with in-memory
  SQLite, and that is accepted.
- The schema is PostgreSQL-specific. Changing databases is not a goal.
