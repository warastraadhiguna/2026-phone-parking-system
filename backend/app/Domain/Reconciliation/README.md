# Reconciliation

Transactions vs payments vs cash ledger vs settlements (Phase 8, ADR-0012).

- `Actions/RunReconciliation`: one WIB business date → an immutable run (totals, lines per
  attendant and location, mismatches), computed in one REPEATABLE READ snapshot.
- `Console/RunReconciliationCommand` (`reconciliation:run {date?}`): scheduled daily for yesterday;
  exit 1 on integrity errors.
- `Internal/ReconciliationCalculator`: independent SQL over the source tables.

Private to this module: `Internal/`. See docs/architecture/overview.md#module-boundaries.
