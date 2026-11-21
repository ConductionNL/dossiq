# Case money is configuration

## ADDED Requirements

### Requirement: The case shows its financial documents (REQ-DQM-001)

The case detail SHALL place `shillinq-financial-documents-panel` with every act the "Fees and payments" panel offers
today. The linked card SHALL list the new panel instead of `shillinq-payment-requests-panel`. Without shillinq the
panel SHALL be missing, not empty.

#### Scenario: A handler sees the leges and the penalty on one case

- GIVEN a case with a paid leges request and a penalty payout with finance
- WHEN the handler opens the case
- THEN the financial documents panel shows both, with their state
@e2e tests/e2e/case-money-is-configuration.spec.ts

### Requirement: A payout names its case (REQ-DQM-002)

A payout raised in the finance app SHALL carry its case in `linkedObjects`.

#### Scenario: The penalty payout reaches finance with its case

- GIVEN a settled amount of 357 euros on case `c-1`
- WHEN the handler prepares the payout
- THEN shillinq receives a payable whose `linkedObjects` hold case `c-1`
- @e2e exclude event contract; covered by PayoutRequesterTest

### Requirement: Money code is named for what it does (REQ-DQM-003)

No controller, service, route name or config key SHALL name a procedure. The old URLs under `/api/termijn/dwangsom/`
and `/api/termijn/ingebrekestellingen` SHALL keep answering for one release; the callback URL SHALL keep answering. Old config keys SHALL be copied to the new keys by repair and still
be read when the new key is empty.

#### Scenario: An old URL still settles the amount

- GIVEN a running accruing amount `a-1`
- WHEN a client posts to `/api/termijn/dwangsom/a-1/beschikking`
- THEN the amount is settled as through `/api/accruing-amounts/a-1/settle`
- @e2e exclude route alias; covered by RenamedRouteAliasesTest

#### Scenario: An install with only the old secret still accepts the callback

- GIVEN `dwangsom_callback_secret` is set and `payout_callback_secret` is not
- WHEN shillinq posts a signed `paid` callback
- THEN the payout row reads paid
- @e2e exclude config migration; covered by MigrateAppConfigKeysTest and PayoutCallbackControllerTest

### Requirement: Payouts are read from shillinq (REQ-DQM-004)

dossiq SHALL NOT write a payout row for a payout the finance app holds. A payout SHALL be read from shillinq, by
its calculation and its case. Without a finance app dossiq SHALL keep the row as before, so the external provider's
callback still has a row to update. An objection SHALL withdraw and raise again a payout only finance holds, as it does
for a stored row. A repair step
SHALL raise every existing `dwangsomUitbetaling` row in shillinq as a payout linked to its case, with its amount,
reference and state, and SHALL leave the stored row readable. The annual penalty audit SHALL list the payouts shillinq
holds for the year.

#### Scenario: A payout prepared before the upgrade is still in the audit

- GIVEN a `dwangsomUitbetaling` row of 357 euros, paid on 3 March 2026, on case `c-1`
- WHEN the upgrade runs and the 2026 audit is opened
- THEN shillinq holds a paid payout of 357 euros linked to case `c-1`, and the audit lists it
- @e2e exclude repair step; covered by PHPUnit on the migration and the audit rows

### Requirement: A forfeited penalty raises an invoice, by configuration (REQ-DQM-005)

A case type MAY say that a forfeited penalty raises an invoice (`forfeitRaisesInvoice`), name the role type of the
person on the case who pays (`forfeitPayerRoleType`), the finance administration that bills (`forfeitAdministration`)
and the kind of payment (`forfeitRequestType`, default `dwangsom`). When an enforcement act on a case of that type
becomes forfeited, dossiq SHALL ask the finance app, once, for a payment request with an invoice behind it for the
forfeited amount, billed to that person and linked to the case. Without the setting, without a payer with an email
address, or without the finance app, nothing SHALL be raised and the act SHALL still read forfeited. A refusal by the
finance app SHALL be logged and SHALL NOT undo the forfeit.

#### Scenario: A forfeited penalty becomes an invoice on the enforcement case

- GIVEN an enforcement case type with `forfeitRaisesInvoice` and an act of 2,500 euros
- WHEN the act is marked forfeited
- THEN the case's financial documents show an issued invoice of 2,500 euros
@e2e tests/e2e/case-money-is-configuration.spec.ts

