# ADR-0009: Liveness and readiness endpoints

- Status: Accepted (additional requirement 3, 2026-09-25)
- Date: 2026-09-25
- Supersedes: the single `GET /api/v1/health` from the initial proposal

## Context

Load balancers and process supervisors need two different answers: "should this process be
restarted?" and "should this instance receive traffic?". One combined endpoint would restart
healthy app processes during a database outage.

## Decision

- `GET /api/v1/health/live`: confirms the Laravel process answers. It touches **no** external
  dependency and never fails because PostgreSQL or Redis is down.
- `GET /api/v1/health/ready`: runs every check in `config/health.php` (PostgreSQL `select 1`,
  Redis `PING` on a fail-fast connection). It returns `200` when all pass and `503
  SERVICE_UNAVAILABLE` with per-check status otherwise.
- No plain `/api/v1/health` and no Laravel default `/up`, so every health URL has exactly one meaning.
- Responses use the standard envelope, send `Cache-Control: no-store`, need no authentication,
  and never include hosts, credentials or exception messages.
- New dependencies (for example the Midtrans API) are **not** added to readiness by default.
  An external provider outage must not take the whole API out of rotation. Such a dependency
  gets its own monitoring instead.

## Consequences

- Clear semantics for Nginx, Docker, systemd or a load balancer, documented in
  [observability.md](../architecture/observability.md#3-health-endpoints).
- Readiness latency during an outage is bounded by connection timeouts (`DB_CONNECT_TIMEOUT`,
  `REDIS_HEALTH_TIMEOUT`).
