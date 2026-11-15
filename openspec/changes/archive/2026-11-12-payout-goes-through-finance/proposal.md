---
kind: code
depends_on: [penalty-is-a-stored-calculation]
---

# Proposal: payout-goes-through-finance

## Why

ADR-118 C16, part 2. A payout dossiq owes someone (the Awb 4:17 penalty for a late decision is the first) was prepared as a row and then waited for an external payment provider; nothing in dossiq raised it with the finance app, and `prepareBetaling` had no caller at all. ADR-107 says money hands off to shillinq. shillinq#1963 adds the inbound payable (`PayableRaisedEvent`) and its stacked PR adds the withdrawal (`PayableCancelRequestedEvent`) that an objection needs. The classes were named after the procedure (dwangsom, notice of default); the code is generic.

## What changes

- New `Money\PayoutRequester`: raises a payout in the finance app with `PayableRaisedEvent` (amount in euros, payee, reference, category, correlation id = the calculation, due date, callback address) and withdraws one with `PayableCancelRequestedEvent`. No answer (finance app absent) returns null, so the row stays prepared for the external provider; a refusal is an error.
- `DwangsomUitbetalingService` becomes `Money\PayoutService`: `preparePayout` raises the payout before the row is written (a refusal writes nothing) and stores `payableId`; `withdrawFromFinance` and `raiseAgain` serve the objection.
- New route `POST /api/termijn/dwangsom/{id}/uitbetaling` (`DwangsomController::payout`, case mutation access) so a payout can be prepared at all.
- `DwangsomBezwaarService`: an objection withdraws the payout from finance before it goes on hold; a payout finance already approved stays with finance and keeps its status. Resolving the objection raises the new amount under a new reference.
- New `Money\SignedPayoutCallback`; `DwangsomPaymentCallbackController` becomes `PayoutCallbackController`. The same URL `/api/dossiq/openconnector/dwangsom-payment-callback` accepts `X-Shillinq-Signature` beside the frozen `X-Procest-Signature` (one secret, `dwangsom_callback_secret`, set on both sides). Finance's `approved`, `paid`, `rejected` map onto the stored `in-handling`, `paid`, `rejected`; an unknown status is a 400 instead of a value the schema refuses.
- `NoticeOfDefaultService` and `NoticeOfDefaultController` become `Money\AccrualNoticeService` and `AccrualNoticeController` (route names follow, URLs do not move).
- `dwangsomUitbetaling` schema 1.1.0 adds `payableId`.

## Depends on

dossiq#3724 (part 1) and shillinq#1963 plus its stacked cancel PR. Without shillinq the payout behaves as before.

## Known gaps

- An administrator sets the secret on both sides: `occ config:app:set dossiq dwangsom_callback_secret` and shillinq `inboundPayableCallbackSecrets` `{"dossiq": "<same>"}`, plus shillinq `inboundPayableExpenseAccounts` for category `dwangsom`.
- No screen calls the new payout route yet.
