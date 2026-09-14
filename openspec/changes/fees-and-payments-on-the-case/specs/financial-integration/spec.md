## ADDED Requirements

### Requirement: A case type declares its fee per intake channel (REQ-FEE-01)

A case type SHALL be able to declare a fee as a list of entries, each
carrying the intake channel, the amount, and the article of the
legesverordening it comes from. Creating a case of a fee-bearing case type
SHALL raise a payment request in shillinq carrying the case, the amount
for that case's intake channel, and the article. Publishing a case type
with a fee and no article SHALL warn, naming the entry.

#### Scenario: the balie and the portal cost different amounts
@e2e tests/e2e/fees-and-payments-on-the-case.spec.ts

- **GIVEN** a case type declaring one amount for the portal and another for the balie
- **WHEN** a case is created through each
- **THEN** each SHALL raise a payment request for its own amount

#### Scenario: the citizen can be told which article
@e2e tests/e2e/fees-and-payments-on-the-case.spec.ts

- **GIVEN** a case with a raised payment request
- **WHEN** a handler opens it
- **THEN** the article of the legesverordening SHALL be named

#### Scenario: a fee with no article warns on publication

- **GIVEN** a case type declaring an amount and no article
- **WHEN** it is published
- **THEN** publication SHALL warn, naming the entry

#### Scenario: a case type with no fee raises nothing

- **GIVEN** a case type declaring no fee
- **WHEN** a case is created
- **THEN** no payment request SHALL be raised

### Requirement: The payment state is on the case and read from shillinq (REQ-FEE-02)

A case SHALL carry a payment state of not required, outstanding, paid or
waived, read from shillinq and held as a projection for listing and
filtering. dossiq SHALL NOT store an amount received, a ledger line or a
payment date as a source of truth. A projection that cannot be refreshed
SHALL read as stale and SHALL NOT read as paid.

#### Scenario: a handler sees whether the leges were paid
@e2e tests/e2e/fees-and-payments-on-the-case.spec.ts

- **GIVEN** a case with an outstanding payment request
- **WHEN** a handler opens it and the case list
- **THEN** both SHALL show the payment state as outstanding

#### Scenario: dossiq holds no amount

- **GIVEN** a paid case
- **WHEN** the dossiq record is read
- **THEN** it SHALL carry the state
- **AND** it SHALL NOT carry an amount received

#### Scenario: an unrefreshable projection is stale, not paid

- **GIVEN** a case whose payment state cannot be refreshed from shillinq
- **WHEN** a handler opens it
- **THEN** the state SHALL read stale
- **AND** it SHALL NOT read paid

### Requirement: A named role sets the payment state by hand (REQ-FEE-03)

A person holding the declared financial role SHALL be able to set a case's
payment state by hand, recording who set it, when, and why. It SHALL be an
act and SHALL NOT be an editable field. A person without that role SHALL be
refused.

#### Scenario: cash at the balie is recorded
@e2e tests/e2e/fees-and-payments-on-the-case.spec.ts

- **GIVEN** a case with an outstanding payment and a person holding the financial role
- **WHEN** they mark it paid with a reason
- **THEN** the state SHALL read paid
- **AND** who, when and why SHALL be recorded

#### Scenario: an ordinary handler cannot change it
@e2e tests/e2e/fees-and-payments-on-the-case.spec.ts

- **GIVEN** a case handler without the financial role
- **WHEN** they try to set the payment state
- **THEN** it SHALL be refused
- **AND** the refusal SHALL name the role

### Requirement: A case type decides whether an unpaid case proceeds (REQ-FEE-04)

A case type SHALL declare whether a case may be handled before its payment
is settled. Where it may not, the acts that depend on payment SHALL be
refused with a 4xx carrying `{message, error}` naming the rule. Where the
payment state cannot be read and the case type requires payment first, the
act SHALL be refused rather than allowed.

#### Scenario: an unpaid aanvraag waits
@e2e tests/e2e/fees-and-payments-on-the-case.spec.ts

- **GIVEN** a case type requiring payment before handling, and an outstanding payment
- **WHEN** a handler moves the case on
- **THEN** it SHALL be refused
- **AND** the refusal SHALL name the payment rule

#### Scenario: a melding does not wait for money
@e2e tests/e2e/fees-and-payments-on-the-case.spec.ts

- **GIVEN** a case type with no payment requirement
- **WHEN** a handler moves a case on
- **THEN** it SHALL proceed

#### Scenario: an unreadable state does not open the gate

- **GIVEN** a case type requiring payment, whose state cannot be read
- **WHEN** a handler moves the case on
- **THEN** it SHALL be refused
- **AND** the refusal SHALL say the payment service is unavailable

### Requirement: A case names the contract it was raised under (REQ-FEE-05)

A case SHALL be able to reference a contract held in shillinq's contract
register. The contract SHALL be able to list the cases raised under it.
dossiq SHALL NOT copy the contract's term, its costs or its renewal date,
and SHALL NOT raise the alert before a contract lapses.

#### Scenario: a case raised under a raamovereenkomst says so
@e2e tests/e2e/fees-and-payments-on-the-case.spec.ts

- **GIVEN** a contract in shillinq's register
- **WHEN** a handler raises a case under it
- **THEN** the case SHALL name the contract

#### Scenario: the contract lists its cases
@e2e tests/e2e/fees-and-payments-on-the-case.spec.ts

- **GIVEN** three cases raised under one contract
- **WHEN** the contract is read
- **THEN** all three SHALL be listed

#### Scenario: dossiq copies no contract term

- **GIVEN** a case referencing a contract
- **WHEN** the dossiq record is read
- **THEN** it SHALL carry the reference
- **AND** it SHALL NOT carry the contract's term, costs or renewal date
