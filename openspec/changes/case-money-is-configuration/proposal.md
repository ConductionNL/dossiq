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
| config `dwangsom_berekening_schema`, `dwangsom_uitbetaling_schema`, `dwangsom_callback_secret`, `ingebrekestelling_schema` | `accruing_amount_schema`, `payout_schema`, `payout_callback_secret`, `notice_of_default_schema`; carried by `CarryRenamedSettingKeys`, old keys still read |
| routes `/api/termijn/ingebrekestellingen...` | `/api/notices-of-default...`; the old URLs stay as aliases |
| payout row (`dwangsomUitbetaling`) known only to dossiq | payout read from shillinq, linked to its case; old rows migrated |

## What changes

- The case detail places `shillinq-financial-documents-panel` where "Fees and payments" is now, with every act the
  old panel had. The linked card lists it instead of `shillinq-payment-requests-panel`.
- `PayoutRequester` sends the case in `linkedObjects` with `PayableRaisedEvent`.
- dossiq stops keeping its own payout row. The payout is read from shillinq. A repair step raises each existing
  `dwangsomUitbetaling` row in shillinq as a payout linked to its case, keeping its state; the stored schema stays
  readable so nothing is lost. The annual penalty audit reads the payouts from shillinq.
- A forfeited enforcement penalty (the offender pays) raises a shillinq invoice linked to the enforcement case.
  The case type says so (`forfeitRaisesInvoice`, with the amount source and the revenue category); no code names
  the procedure.
- The renames in the table above, with route aliases (`RenamedRouteAliasesTest`) and the config key carry
  (`CarryRenamedSettingKeys`).
- Schema slugs `penaltyPaymentCalculation`, `dwangsomUitbetaling` and `noticeOfDefault` stay: they are in stored data.
  Their titles and descriptions lose the procedure name.

## Users keep

- The DqTermijnen penalty audit and its Finance column, the quarterly report.
- Raising the leges for the intake channel, sending the payment link, recording a counter payment.
- The payment state on the case and in the case list.
- The objection that withdraws a payout and raises the new amount.
- The signed callback URL, with both signature headers.

## Decided (Ruben, 23 Oct)

- One "Financial documents" card, narrowed per placement with `kinds` and `actions`.
- A document links to several objects (`linkedObjects`), frozen once issued.
- A forfeited enforcement penalty raises a shillinq invoice, as case type configuration.
- dossiq's payout row retires: payouts are read from shillinq, existing rows migrate, the audit reads shillinq.
- The `/api/termijn/` aliases live one release; the callback URL stays for good.
