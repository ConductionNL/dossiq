# Notice of default from the case

## Why

Row 8.32 (register a notice of default, accrue the statutory penalty and signal the payout to finance). Most of the chain is built: `POST /api/notices-of-default` (`accrualNotice#register`) stores a notice, the penalty accrues as a stored calculation (`penalty-is-a-stored-calculation`), `POST /api/accruing-amounts/{id}/payout` raises the payout with the finance app (`payout-goes-through-finance`), and DqTermijnen lists the notices (`notices-of-default-by-case`). Two pieces are missing: no screen registers a notice (nothing under `src/` posts to `/api/notices-of-default`), and the screen that prepares the payout is task 6 of `payout-goes-through-finance`, left as a follow-up.

## What changes

- A header action on the case, "Ingebrekestelling registreren", for case types that carry a penalty on a late decision (`case-type-penalty-on-late-decision`). It records the date received and the sender, and starts the accrual.
- An action on a notice row (DqTermijnen card and the case) that prepares the payout once the amount is definitive, and shows where the payout stands.
- Generic names throughout: notice of default, accruing amount, payout (decision 182).

## Impact

- `src/manifest.json` CaseDetail headerActions `notice-of-default-register`; `src/dialogs/NoticeOfDefaultDialog.vue`; row action `payout-prepare` on the notices card.
- Supersedes task 6 of `payout-goes-through-finance` (that change can be ticked and archived once this one lands).
