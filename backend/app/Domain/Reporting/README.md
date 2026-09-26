# Reporting

Dashboards, reports and queued exports (Phase 10, ADR-0014). Read-only over the source tables.

- `Services/DashboardMetrics`: operational and executive dashboards (cached 30 s).
- `Services/ReportCatalog`: the §32 reports (columns plus streamed rows).
- `Actions/RequestReportExport` + `Jobs/GenerateReportExport`: CSV/XLSX/PDF on the queue.
- `Console/PruneReportExportsCommand` (`reports:prune-exports`, daily): expires files after 7 days.

Private to this module: `Internal/` (ExportWriter). See docs/architecture/overview.md#module-boundaries.
