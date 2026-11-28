# Payout

## ADDED Requirements

### Requirement: A payout is handed to the finance app (REQ-PAY-001)

When dossiq prepares a payout of a settled amount and a finance app answers `PayableRaisedEvent`, the system SHALL raise the payout before it writes the payout row, SHALL store the payable id on the row, and SHALL write nothing when finance refuses. Without an answer the row SHALL be written as prepared, as before.

@e2e exclude unit only until a screen calls the payout route; PayoutServiceTest, PayoutRequesterTest

#### Scenario: The penalty payout reaches finance

- GIVEN a settled calculation of 357 euros
- WHEN the caseworker prepares the payout to J. Burger
- THEN shillinq receives a payable of 357.00 EUR with the reference, the calculation as correlation id and the callback address, and the payout row keeps the payable id

### Requirement: An objection withdraws the payout from finance (REQ-PAY-002)

An objection against the amount SHALL withdraw a payout that is with finance (`PayableCancelRequestedEvent`) before it is put on hold. A payout finance already approved SHALL keep its status. Resolving the objection SHALL raise the new amount under a new reference.

@e2e exclude unit only; DwangsomBezwaarServiceTest

#### Scenario: Objection, then a lower amount

- GIVEN a payout of 500 euros with finance
- WHEN an objection is registered and later resolved at 300 euros
- THEN finance withdrew the first payable, and a new payable of 300 euros under a new reference is with finance

### Requirement: One signed callback URL for finance and the provider (REQ-PAY-003)

`/api/dossiq/openconnector/dwangsom-payment-callback` SHALL accept a body signed in `X-Shillinq-Signature` or the frozen `X-Procest-Signature` with `dwangsom_callback_secret`, SHALL refuse with 401 when neither matches or no secret is set, SHALL map `approved`, `paid`, `rejected` onto `in-handling`, `paid`, `rejected`, and SHALL refuse an unknown status with 400.

@e2e exclude unit only; PayoutCallbackControllerTest

#### Scenario: Finance reports the payout paid

- GIVEN the shared secret is set on both sides
- WHEN shillinq posts status `paid` with the date and bank reference, signed
- THEN the payout row reads `paid` with that date and bank reference
