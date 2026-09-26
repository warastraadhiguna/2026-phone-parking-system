# ADR-0013: Anomaly signals and the review queue

- Status: Accepted (Phase 9)
- Date: 2026-09-26

## Context

Phase 9 asks for an immutable audit log, geofence and mock-location signals, a basic
impossible-movement rule, and a review queue. Earlier phases already store review flags on
transactions and shifts: geofence, mock location, tariff mismatch, stale offline data, clock
skew, late payment and others. Phase 8 added reconciliation mismatches.

## Decision

1. **Signals stay where they arise.** Modules keep writing `review_flags` on their own records.
   Reconciliation keeps its mismatches. Signals are never rejections: an offline field record is
   always stored (ADR-0008).
2. **Impossible movement (basic rule):** a transaction is flagged `IMPOSSIBLE_MOVEMENT` when:
   - it is at least `movement_min_distance_m` (1000 m) from the same attendant's closest earlier
     transaction with a usable GPS fix (by device time); and
   - that distance was covered faster than `movement_max_speed_kmh` (60 km/h).

   Both values are settings. Fixes that are worse than `gps_max_accuracy_m` are ignored. It is a
   signal only.
3. **Review queue** `anomaly_reviews` (FraudReview module):
   - `CollectAnomalies` copies every flag and every mismatch from the latest reconciliation run
     of each date into one item. It is idempotent (unique source + entity + code) and runs every
     5 minutes. It only reads other modules' tables.
   - Severity comes from the code:
     - HIGH: mock location, impossible movement, late payment, payment amount mismatch, device
       mismatch, reconciliation ERROR;
     - LOW: clock skew, stale offline, overdue shift, location inactive;
     - MEDIUM: everything else.
4. **Decision:** `OPEN → CONFIRMED | DISMISSED` with a note of at least 5 characters, by
   `anomalies.review` (Supervisor). It is final: the facts and the decision are frozen and never
   deleted (trigger), and every decision is audited. A decision is a **finding only**. Money is
   corrected only through void, refund or settlement.
5. **Audit log viewer:** read-only (`audit.view`), with filters for action, actor, entity,
   request ID and date. There are no edit or delete routes. Other modules read the trail through
   `Audit\Services\AuditLogBrowser`, so the architecture rule "only Audit touches AuditLog" still
   holds.

## Consequences

- One queue for every kind of signal. Supervisors work by priority, and the history of findings
  is permanent.
- The impossible-movement rule is deliberately simple (pairwise, previous transaction only). More
  advanced scoring (patterns per attendant, per location) can later add codes to the same queue.
