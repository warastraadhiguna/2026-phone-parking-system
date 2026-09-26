# Coding Conventions

## General

- Optimise for correctness, auditability and data integrity, not for amount of code.
- Follow the module rules in [architecture/overview.md §4](architecture/overview.md#4-module-boundaries).
- Add a dependency only when it removes real work or risk. Record why in the PR or ADR.
- No secrets, credentials, tariffs, base URLs or policy thresholds in code or in the repository.

## PHP / Laravel

- PHP 8.4, Laravel 13. Formatting: **Laravel Pint** default preset (`composer lint`).
- Static analysis: **Larastan level 8** on `app`, `bootstrap/app.php`, `config`, `database`, `routes`
  (`composer analyse`). The level only goes up. Use no baseline file. Fix the code instead.
- `declare(strict_types=1)` is not required; use typed properties, parameters and returns everywhere.
- Use `final` for classes not designed for extension (controllers, value objects, services).
- Enums for every status/type. Status changes go through an enum method such as
  `canTransitionTo()`. Never assign a raw string status.
- Money is `int` rupiah. No float arithmetic on money.
- Do not call `env()` outside `config/*.php` (enforced by an architecture test). Read `config()` instead.
- Controllers stay thin: validate (FormRequest) → call one Action → return `ApiResponse` or an Inertia page.
- Actions: one public `handle()` / `__invoke()`. Wrap multi-row financial writes in
  `DB::transaction()`. Use `lockForUpdate()` when the next state depends on the current one.
- Expected failures: throw `ApiException` or a module exception with an `ErrorCode`. Never return error arrays.
- Logging: include identifiers (IDs, UUIDs), not personal or financial payloads. See
  [observability](architecture/observability.md).
- Queue anything slow (exports, notifications, aggregation). Request paths that create
  transactions must not wait on it.

## Naming

| Thing | Convention | Example |
|---|---|---|
| Module | PascalCase singular | `CashSettlement` |
| Action | verb + noun | `StartShift`, `RecordCashTransaction` |
| Table | snake_case plural | `cash_ledger_entries` |
| Money column | `*_amount` (bigint) | `charged_tariff_amount` |
| Device/server time | `*_at_device`, `*_at_server` | `started_at_device` |
| API route | kebab-case plural nouns under `/api/v1` | `/api/v1/parking-transactions` |
| Error code | UPPER_SNAKE | `SHIFT_NOT_ACTIVE` |

## Database and migrations

See [database/README.md](database/README.md). Summary: `bigint` money, `timestamptz`, FKs,
CHECK constraints, partial unique indexes for invariants, reversible migrations, append-only
triggers for ledger and audit tables.

## Tests (Pest)

- `tests/Unit`: pure logic, no framework boot (tariff selection, cash math, geofence, transitions).
- `tests/Feature`: HTTP and DB behaviour against **real PostgreSQL** (`pati_parking_test`).
  Use `RefreshDatabase`. Never switch to SQLite.
- `tests/Arch`: module boundary and hygiene rules. A new rule needs a deliberate violation
  checked once, to prove it can fail.
- Every endpoint and every critical business rule (master doc §45, §46) gets a test. Test the
  failure paths too: duplicates, stale state, wrong status, concurrent updates.
- Run everything with `docker compose exec app composer check`.

## Frontend (admin, `backend/resources/js`)

- React + TypeScript (`strict`, `noUncheckedIndexedAccess`). `npm run build` typechecks first.
- Structure: `Pages/` (Inertia page components, one per route; page-local types go in a
  sibling `.ts` file), `Layouts/`, `Components/`, `hooks/`, `types/`, and `lib/` (pure helpers
  such as date formatting). Import with the `@/` alias.
- UI language is Indonesian. The server sends timestamps in UTC; the UI shows them in WIB (`lib/format.ts`).
- Hide actions the user lacks permission for (`usePermissions().can(...)`). This is cosmetic;
  the server enforces every permission.
- Server data arrives only as Inertia props. Shared props are typed in `types/index.ts` and must
  mirror `HandleInertiaRequests::share()`.
- Pages render what the server decided. No business rules in the browser (amounts, statuses,
  permissions). Authorization is enforced on the server.
- Styling: Tailwind CSS v4 utilities.

## Git

- Default branch `main`. Short-lived branches per phase or feature: `phase-1/identity`.
- Commit messages: imperative subject ≤ 72 chars, body explains why.
- Never commit `.env`, keys, keystores or credential files (`.gitignore`).
