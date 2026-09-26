# Roles and Permissions

Source of truth: `App\Domain\Identity\Enums\Role::permissions()` (code, versioned in git).
`php artisan identity:sync-roles` applies it to the database. It is idempotent, removes
permissions that are no longer in the catalogue, and audits changes as
`ROLE_PERMISSIONS_SYNCED`. Run it on every deploy. The admin page **Peran & Hak Akses**
(`/roles`) shows the live matrix read-only.

Changing the matrix changes who may do what with public money, so it **requires owner
approval** and a reviewed code change. There is deliberately no UI for editing roles.

## Account types

| Account type | Channel | Roles |
|---|---|---|
| `STAFF` | Admin web (session + CSRF) only | Every role except Juru Parkir |
| `ATTENDANT` | Android app (tokens) only | Juru Parkir |

The account type is fixed at creation. It is checked at login and on every request.

## Separation of duties

- **Super Admin** configures the system, users and master data, and sees everything. It does
  **not** verify settlements, approve voids or adjustments, or run reconciliation.
- **Finance** is the only role that verifies settlements and runs reconciliation.
- **Supervisor** is the only role that approves voids and adjustments. It cannot request voids.
- **Auditor** and **Executive Viewer** are read-only (enforced by a test).
- Mobile permissions belong only to Juru Parkir (enforced by a test).
- Permissions are granted only through roles. Direct per-user permissions are not used.

## Matrix

S = Super Admin, D = Admin Dishub, O = Operator Parkir, F = Keuangan, V = Supervisor,
A = Auditor, E = Executive Viewer, J = Juru Parkir. "Phase" is when the permission starts
guarding a feature.

| Permission | Phase | S | D | O | F | V | A | E | J |
|---|---|---|---|---|---|---|---|---|---|
| users.view | 1 | ● | | | | | ● | | |
| users.manage | 1 | ● | | | | | | | |
| roles.view | 1 | ● | | | | | ● | | |
| audit.view | 9 | ● | | | | | ● | | |
| system.configure | 3 | ● | | | | | | | |
| payment.configure | 6 | ● | | | | | | | |
| locations.view | 2 | ● | ● | ● | ● | ● | ● | ● | |
| locations.manage | 2 | ● | ● | | | | | | |
| attendants.view | 2 | ● | ● | ● | ● | ● | ● | | |
| attendants.manage | 2 | ● | ● | | | | | | |
| assignments.manage | 2 | ● | ● | | | | | | |
| devices.view | 2 | ● | ● | ● | | ● | ● | | |
| devices.manage | 2 | ● | ● | | | | | | |
| tariffs.view | 2 | ● | ● | ● | ● | ● | ● | | |
| tariffs.manage | 2 | ● | ● | | | | | | |
| tariffs.approve | 2 | ● | ● | | | | | | |
| shifts.view | 3 | ● | ● | ● | ● | ● | ● | | |
| shifts.force_close | 3 | | | | | ● | | | |
| transactions.view | 4 | ● | ● | ● | ● | ● | ● | | |
| transactions.void_request | 4 | | | ● | | | | | |
| transactions.void_approve | 4 | | | | | ● | | | |
| payments.view | 6 | ● | ● | ● | ● | ● | ● | | |
| payments.refund_record | 6 | | | | ● | | | | |
| settlements.view | 7 | ● | ● | | ● | ● | ● | | |
| settlements.verify | 7 | | | | ● | | | | |
| reconciliation.view | 8 | ● | ● | | ● | | ● | | |
| reconciliation.run | 8 | | | | ● | | | | |
| adjustments.approve | 8 | | | | | ● | | | |
| anomalies.view | 9 | ● | ● | ● | | ● | ● | | |
| anomalies.review | 9 | | | | | ● | | | |
| dashboard.operational | 10 | ● | ● | ● | ● | ● | | | |
| dashboard.executive | 10 | ● | ● | | | | | ● | |
| reports.view | 10 | ● | ● | | ● | ● | ● | | |
| reports.export | 10 | ● | ● | | ● | | ● | | |
| mobile.shift.operate | 3 | | | | | | | | ● |
| mobile.transaction.create | 4 | | | | | | | | ● |
| mobile.payment.qris | 6 | | | | | | | | ● |
| mobile.settlement.submit | 7 | | | | | | | | ● |
| mobile.void.request | 4 | | | | | | | | ● |

## Open points for the owner

1. **Tariff approval** (`tariffs.approve`): held by Super Admin and Admin Dishub. Phase 2
   will enforce "creator ≠ approver". Should approval instead belong to a specific office
   (for example Kepala Dinas)?
2. **Executive Viewer** sees only the executive dashboard and locations (master doc §6.7).
   Should it also see reports?
3. **Operator** can request voids but not approve them (master doc §6.3, §28). Confirm.
