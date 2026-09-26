# FraudReview

Review queue for anomaly signals (Phase 9, ADR-0013).

- `Actions/CollectAnomalies` (`anomalies:collect`, every 5 minutes): transaction and shift review
  flags and reconciliation mismatches → `anomaly_reviews` (idempotent).
- `Actions/DecideReview`: Supervisor records CONFIRMED / DISMISSED with a note. It is final and
  audited, and it never changes money.

Signals themselves are produced by their owner modules (e.g. `ParkingTransaction\Services\MovementCheck`).
Private to this module: `Internal/`. See docs/architecture/overview.md#module-boundaries.
