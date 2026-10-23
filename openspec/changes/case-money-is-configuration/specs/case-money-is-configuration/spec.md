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

A payout raised in the finance app SHALL carry its case as `subject`.

#### Scenario: The penalty payout reaches finance with its case

- GIVEN a settled amount of 357 euros on case `c-1`
- WHEN the handler prepares the payout
- THEN shillinq receives a payable with subject case `c-1`
- @e2e exclude event contract; covered by PayoutRequesterTest

### Requirement: Money code is named for what it does (REQ-DQM-003)

No controller, service, route name or config key SHALL name a procedure. The old URLs under `/api/termijn/dwangsom/`
and the callback URL SHALL keep answering as before. Old config keys SHALL be copied to the new keys by repair and still
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
