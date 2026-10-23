---
kind: code
depends_on: [payout-goes-through-finance, penalty-is-a-stored-calculation, case-type-penalty-on-late-decision]
---

# Proposal: case-money-is-configuration

## Why

Ruben, 23 Oct: "we should have generic support for attaching quotations, invoices etc to object. And invoices should
have payment support from shillinq." His standing rule: procedures are configuration, code is generic and named for
what it does. Users keep their features.

shillinq change `financial-documents-on-any-object` gives every object a list of its financial documents and a panel
to show and create them. This change is dossiq's half: the case shows that panel, and the penalty for a late decision
(Awb 4:17 dwangsom) becomes configuration on top of it.

Most of the penalty is generic already. The amount is an OpenRegister calculation (`penalty-is-a-stored-calculation`),
the payout goes to shillinq (`payout-goes-through-finance`), and the case type says whether a late decision costs a
penalty (`case-type-penalty-on-late-decision`). What is still named after the procedure:

| Now | Becomes |
|---|---|
| `DwangsomController` (show, beschikking, bezwaar, bezwaarHeroverweging) | `AccruingAmountController` (show, settle, objection, reconsider) |
| `DwangsomBezwaarService` | `Money\AmountObjectionService` |
| routes `/api/termijn/dwangsom/{id}...` | `/api/accruing-amounts/{id}...`; the old URLs stay as aliases |
| config `dwangsom_berekening_schema`, `dwangsom_uitbetaling_schema`, `dwangsom_callback_secret` | `accruing_amount_schema`, `payout_schema`, `payout_callback_secret`, migrated by repair, old keys still read |
| payout row known only to dossiq | payout raised with the case as `subject`, so it shows on the case |

## What changes

- The case detail places `shillinq-financial-documents-panel` where "Fees and payments" is now, with every act the
  old panel had. The linked card lists it instead of `shillinq-payment-requests-panel`.
- `PayoutRequester` sends the case as `subject` with `PayableRaisedEvent`.
- The renames in the table above, with route aliases (`RenamedRouteAliasesTest`) and the config key repair
  (`MigrateAppConfigKeys`).
- Schema slugs `penaltyPaymentCalculation`, `dwangsomUitbetaling` and `noticeOfDefault` stay: they are in stored data.
  Their titles and descriptions lose the procedure name.

## Users keep

- The DqTermijnen penalty audit and its Finance column, the quarterly report.
- Raising the leges for the intake channel, sending the payment link, recording a counter payment.
- The payment state on the case and in the case list.
- The objection that withdraws a payout and raises the new amount.
- The signed callback URL, with both signature headers.

## Open, for Ruben

See `R20/DQ-FIN/PROPOSAL.md`: one panel or one per kind, the enforcement penalty ("last onder dwangsom", where the
offender pays), and whether dossiq keeps its payout row.
