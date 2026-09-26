# ADR-0014: Dashboards, reports and exports

- Status: Accepted (Phase 10)
- Date: 2026-09-26

## Context

Master doc §29–§32 and §39 require:
- an operational and an executive dashboard;
- a monitoring map (without vehicle tracking);
- eleven minimum reports, with CSV/XLSX/PDF export;
- large exports processed on the queue;
- transaction creation never waiting for heavy work.

## Decision

1. **One revenue definition everywhere:** transactions COMPLETED + VOID_REQUESTED, by server
   time in the WIB business day. Dashboards, reports and reconciliation (ADR-0012) therefore
   always agree.
2. **Dashboards** (`Reporting\Services\DashboardMetrics`) are read-only SQL on the source
   tables, cached in Redis for 30 s (derived data only, ADR-0003). The landing page shows:
   - the operational view to `dashboard.operational`;
   - the executive view to `dashboard.executive`, with a switch for users who hold both;
   - a welcome card to other roles.

   The monthly target is a setting (`monthly_revenue_target`; 0 = no target).
3. **Map:** Leaflet with a tile URL from configuration (`REPORTING_MAP_TILE_URL`; empty = points
   only). It shows location status, whether a shift is open, and today's counts. It never shows
   vehicle or attendant tracks.
4. **Reports** (`ReportCatalog`) are streamed SQL definitions with an on-screen preview (100 rows).
   Access rules:
   - viewing needs `reports.view`;
   - exporting needs `reports.export`;
   - a report never widens access: the audit-log report also requires `audit.view`.
5. **Exports** always run on the queue (`GenerateReportExport`):
   - CSV (UTF-8 BOM, formula cells neutralised) and XLSX (openspout) are streamed, any size;
   - PDF (dompdf, remote resources disabled) is limited to 2000 rows;
   - files are private, downloadable only by the requester (audited), and deleted after 7 days
     (`reports:prune-exports`); the record stays.
6. The date range is limited to `REPORTING_MAX_RANGE_DAYS` (366).

## Consequences

- No report or dashboard query runs inside transaction creation. Heavy exports never block a
  web request.
- If data volume grows, the dashboard queries can move to pre-aggregated daily tables
  (fed by reconciliation) without changing the pages.
