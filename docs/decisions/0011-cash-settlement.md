# ADR-0011: Cash settlement and outstanding cash

- Status: Accepted (Phase 7 implementation defaults; owner items marked **[owner]**)
- Date: 2026-09-26

## Context

Master doc §11 and §13 require expected − deposited = outstanding, attendant-submitted deposits,
and finance verification. Scenario D is: expected 100.000, submitted 90.000, verified, outstanding
10.000. Two things are still owner decisions: the approval flow for deposits (§62 #4), and
whether settlement is per shift or per day (§62 #11).

## Decision

1. **The ledger is the only money record.** A submission moves nothing. Only a **VERIFIED**
   settlement writes one `SETTLEMENT_OUT` entry, and its amount is the amount **finance
   counted** (`verified_amount`), not the amount declared. Outstanding cash is simply the ledger
   balance.
2. **Counted ≠ declared** is allowed but needs a note. The difference stays outstanding (or, if
   more was handed over, the balance drops by the counted amount).
3. **Never more than held:** the counted amount can be at most the attendant's balance, checked
   under the balance row lock. The database also has a CHECK: `SETTLEMENT_OUT ⇒ balance_after ≥ 0`.
   The declared amount at submission is also limited to the server balance. The app submits only
   after everything is synced.
4. **Status:** `SUBMITTED → VERIFIED | REJECTED | CANCELLED` (cancelled by the attendant). A
   decided settlement is frozen, and the submission data is frozen from the start (trigger).
   Rejection needs a reason.
5. **Four eyes:** the submitter can never decide (DB CHECK `decided_by <> submitted_by`). Deciding
   needs `settlements.verify` (Finance only, existing matrix).
6. **One open submission per attendant** (partial unique index), so finance handles deposits one
   at a time.
7. **Idempotent** on `settlement_uuid`. The payload fingerprint includes the proof photo's SHA-256.
8. **Policy-neutral:** a settlement belongs to the attendant, with an optional `shift_id`.
   Summaries are provided for all time, per shift and per business day (WIB), so either
   policy **[owner]** can be applied without a schema change.
9. The optional **proof photo** is kept on the private disk and served only to `settlements.view`.

## Consequences

- Deposits can never create or lose money outside the ledger. `cash:verify-balances` keeps
  proving the derived balance.
- Short deposits remain visible as outstanding until they are settled or explained. Adjustments
  with approval (`adjustments.approve`) are a later phase.
- If the owner picks per-shift settlement, the app can make `shift_uuid` mandatory; if per-day,
  the daily summary is already available.
