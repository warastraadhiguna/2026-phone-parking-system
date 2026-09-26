# Cash Summary & Settlement API

For the Android app. Requires `Authorization: Bearer <access_token>` and an **ACTIVE** device.
Rules: master doc §11, §13, Scenario D; [ADR-0011](../decisions/0011-cash-settlement.md).
Amounts are integer rupiah.

**Rule:** submitting a deposit moves no money. Only when finance **verifies** the counted amount
does the ledger get one `SETTLEMENT_OUT`. Outstanding = the ledger balance.

## `GET /api/v1/cash/summary`

```json
{
  "cash_balance": 1000,
  "total": { "collected": 6000, "deposited": 5000, "outstanding": 1000, "cash_in": 6000, "reversals": 0, "adjustments": 0 },
  "today": { "date": "2026-09-25", "collected": 6000, "deposited": 5000, "outstanding": 1000, "…": "…" },
  "open_shift": { "shift_uuid": "…", "collected": 6000, "deposited": 0, "outstanding": 6000, "…": "…" },
  "pending_settlement": null,
  "as_of": "2026-09-25T10:00:00+07:00"
}
```

- `collected` = cash-ins + reversals + adjustments.
- `deposited` = verified settlements.
- `today` uses the WIB business day. `open_shift` is null without an open shift.

## `POST /api/v1/settlements`

Permission `mobile.settlement.submit`. The body is JSON, or multipart when a proof photo is sent.

| Field | Rule |
|---|---|
| `settlement_uuid` | required uuid, generated once, reused on retry |
| `amount` | required integer ≥ 1, ≤ current server cash balance |
| `shift_uuid` | optional; a shift of this attendant |
| `notes` | optional, ≤ 500 |
| `proof` | optional image jpg/png ≤ 5 MB (deposit slip) |

Response `201` (`200` on replay): `{ "settlement": {…}, "replayed": false, "cash_balance": 6000 }`.

```json
{ "settlement_uuid": "…", "settlement_number": "STL-260925-00000001", "status": "SUBMITTED", "status_label": "Menunggu verifikasi",
  "amount": 5000, "verified_amount": null, "balance_at_submission": 6000, "notes": null, "has_proof": false,
  "submitted_at": "…", "decided_at": null, "decision_note": null }
```

| Case | Result |
|---|---|
| Same UUID, same data | `200`, `replayed: true` |
| Same UUID, different data | `409 SYNC_CONFLICT` |
| Amount > server balance | `422 SETTLEMENT_INVALID`, `details.cash_balance`. Sync first |
| Another submission still SUBMITTED | `422 SETTLEMENT_INVALID`, `details.open_settlement_uuid` |
| Unknown `shift_uuid` | `422 SETTLEMENT_INVALID` |

## `GET /api/v1/settlements`, `GET /api/v1/settlements/{uuid}`

Own settlements, newest first (paginated, 20 per page).

## `POST /api/v1/settlements/{uuid}/cancel`

Withdraws a submission that is still `SUBMITTED` (submitter only). Otherwise `409 CONFLICT`.

## Finance (Control Center, not API)

**Setoran** (`settlements.view`) lists pending submissions first, plus outstanding cash per
attendant. On the detail page, `settlements.verify` (Finance) either:
- **verifies** with the counted amount (a note is required when it differs from the declared
  amount); or
- **rejects** with a reason.

The submitter can never decide, and a decided settlement never changes.
