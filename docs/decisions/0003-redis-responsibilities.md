# ADR-0003: Redis responsibilities

- Status: Accepted
- Date: 2026-09-25

## Context

The master document (§38) assigns Redis to cache, queue, rate limiting and optional
distributed locks, and forbids using it as a source of financial truth.

## Decision

Redis 7.4 (AOF persistence enabled) is used for:

| Use | Laravel config | Loss impact |
|---|---|---|
| Queue (jobs) | `QUEUE_CONNECTION=redis` | Pending jobs lost. Jobs must be re-derivable from PostgreSQL state. Failed jobs are stored in PostgreSQL. |
| Cache | `CACHE_STORE=redis` (connection `cache`, DB 1) | Recomputed |
| Admin sessions | `SESSION_DRIVER=redis`, encrypted | Admins log in again |
| Rate limiting | cache store | Limits reset |
| Locks | `Cache::lock()`, scheduler `onOneServer()` | Short window of possible double-run. Financial correctness still relies on DB locks and constraints. |

Redis is **not** used for:

- any financial balance, counter or status of record;
- idempotency decisions (these use DB unique constraints);
- the only copy of any data.

## Consequences

- A Redis outage degrades the service (readiness fails, sessions and queue unavailable) but
  cannot corrupt or lose financial data.
- Jobs must be idempotent and retry-safe (`--tries=3`).
- Production Redis persistence and eviction policy must be reviewed before go-live (master doc §54).
  The queue requires `noeviction`.
