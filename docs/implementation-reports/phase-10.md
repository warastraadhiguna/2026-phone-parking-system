# Implementation Report — Phase 10

```text
PHASE:
Phase 10 — Dashboard & Reporting

STATUS:
DONE
```

Date: 2026-09-26. Continued without waiting for approval, as instructed by the owner.

## IMPLEMENTED

- **Operational dashboard (§29)** on the landing page for `dashboard.operational`. Tiles:
  - today's total, cash and QRIS revenue, and transaction count;
  - open shifts, active attendants and active locations;
  - outstanding cash;
  - pending settlements (count and amount);
  - QRIS failures, expired and waiting payments today;
  - open anomalies by severity.

  Every tile links to the page with its detail.
- **Executive dashboard (§30)** for `dashboard.executive` (switchable for users with both):
  - revenue today and month to date;
  - **target progress** (new setting `monthly_revenue_target`, 0 = no target);
  - cash vs QRIS for the month, outstanding cash;
  - top 5 locations of the month;
  - a 30-day revenue trend (cash/QRIS);
  - a map.
- **Monitoring map (§31):** Leaflet map of all locations. Colour shows active with a shift /
  active without a shift / inactive; the size shows today's transactions; the popup shows the
  counts and revenue. There is no vehicle or attendant tracking. The tile URL comes from
  configuration (default OpenStreetMap; empty = points only).
- **Reports (§32), all 11:**
  - revenue by date, by location, by attendant, by vehicle type;
  - cash vs QRIS, cash outstanding (current);
  - settlements, reconciliation (latest run per date), transaction detail;
  - anomalies, audit log.

  Filters: date range (max 366 days), location, attendant, payment method. On-screen preview of
  100 rows. The audit-log report also needs `audit.view` (a report never widens access).
- **Exports:** CSV, XLSX and PDF, **always on the queue** (`GenerateReportExport`, §39).
  - CSV (UTF-8 BOM, formula injection neutralised) and XLSX (openspout) are streamed, any size.
  - PDF (dompdf, remote resources disabled) is limited to 2000 rows.
  - Files are private and downloadable only by the requester. Request and download are
    audited.
  - Files are deleted after 7 days (`reports:prune-exports` daily); the record stays.
- **One revenue definition** for dashboards, reports and reconciliation: COMPLETED +
  VOID_REQUESTED, by server time in the WIB business day. Dashboard figures are cached for 30 s.

## FILES CHANGED

Backend:
- `app/Domain/Reporting/` (new):
  - `Services/{DashboardMetrics, ReportCatalog}`
  - `Enums/{ReportType, ExportFormat, ExportStatus}`, `Data/ReportParams`, `Models/ReportExport`
  - `Actions/RequestReportExport`, `Jobs/GenerateReportExport`, `Internal/ExportWriter`
  - `Console/PruneReportExportsCommand`, `README.md`
- changed:
  - `Audit/Services/AuditLogBrowser` (export stream, no metadata)
  - `Audit/Enums/AuditAction` (+2), `SystemConfiguration/Enums/SettingKey` (+1)
- Admin:
  - changed: `Http/Admin/DashboardController`
  - new: `Http/Admin/Reporting/ReportController`
- Config:
  - new: `config/reporting.php`
  - changed: `.env.example`
  - `composer.json`: `openspout/openspout ^5.12`, `dompdf/dompdf ^3.1`
- Wiring (changed): `routes/web.php`, `routes/console.php`, `bootstrap/app.php`
- Migration (new): `2026_09_25_900000_create_report_exports_table`
- Frontend:
  - new: `Components/LocationMap.tsx`, `Pages/Reports/Index.tsx`
  - changed: `Pages/Home.tsx` (dashboards), `Layouts/AdminLayout.tsx`
  - `package.json`: `leaflet 1.9.4`, `@types/leaflet`
- Tests:
  - new: `tests/Feature/Reporting/ReportingTest.php`
  - changed: `tests/Pest.php`, `Operations/SettingsTest.php`

Docs:
- new: `docs/decisions/0014-dashboards-and-reports.md`, this report
- updated: `docs/decisions/README.md`, `docs/database/README.md`, `docs/architecture/overview.md`

## DATABASE CHANGES

| Object | Notes |
|---|---|
| `report_exports` | Operational records (not financial): requester, type, params, status, file, row count; CHECK format and status |

The migration is reversible.

## API

No mobile API. Admin web:
- `/` (dashboards by permission);
- `GET reports` (`reports.view`);
- `POST reports/exports`, `GET reports/exports/{id}` (`reports.export`, requester only).

## TESTS

- `composer check` → Pint PASS, Larastan level 8 OK, **415 passed (2368 assertions)**. Phase 10
  adds 5 tests.

| ReportingTest case | Covers |
|---|---|
| Operational dashboard | All §29 figures from a known day (2 cash + 1 QRIS + pending settlement + HIGH anomaly), map point staffed |
| Executive dashboard | Today/month, target 60,000 → 10%, 30-point trend, top location; Auditor sees the welcome card |
| Reports | 10 report types with exact values; audit-log report 403 for Finance and OK for Auditor; range > 366 days refused; Executive Viewer 403 |
| Exports | CSV (BOM, header + 3 rows), XLSX (zip signature), PDF (`%PDF`) through the queue job; requester-only download (another Finance user 404, Supervisor cannot export); audit of request and download; retention prune after 8 days |
| CSV injection | `=`, `+`, `@` prefixed with `'`; negative numbers and plates unchanged |

- Dev stack: a real export through the Redis queue worker gave DONE, 2 rows, XLSX file.

Issue found during the phase: PostgreSQL returns `SUM(bigint)` as a numeric string, which
the preview test caught. Report rows now convert integer and decimal strings to numbers, so the
preview and XLSX receive numbers.

## STATIC ANALYSIS

Pint PASS, Larastan level 8 OK (two type annotations fixed), architecture tests pass,
TypeScript build passes.

## SECURITY NOTES

- Reports reuse existing permissions and never widen access (the audit-log report needs
  `audit.view`).
- Export files:
  - private disk, requester-only download, `no-store`, `nosniff`;
  - short retention; the audit metadata column is excluded;
  - CSV formula injection neutralised; dompdf cannot fetch remote resources.
- Dashboards are read-only. The cache holds derived figures only.

## ARCHITECTURE DECISIONS / ADR

- New **ADR-0014** (dashboards, reports and exports).

## KNOWN LIMITATIONS

- Dashboard queries run on the live tables (cached for 30 s). The Phase 11 benchmark measures
  them. If volume grows, they can move to daily aggregates.
- PDF exports are limited to 2000 rows by design.
- There is no official report layout yet (owner item §62 #12). The columns follow §32.
- The default map tiles (OpenStreetMap) are fine for development. Production needs a tile
  service with a suitable usage policy (configurable).

## DEVIATIONS FROM APPROVED PLAN

None.

## BLOCKERS

None. Owner items: the official report formats (§62 #12) and the monthly revenue target value.

## NEXT RECOMMENDED PHASE

Phase 11 — Hardening.
