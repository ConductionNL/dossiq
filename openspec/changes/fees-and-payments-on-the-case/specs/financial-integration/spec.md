## ADDED Requirements

### Requirement: A case type's fee is published in shillinq, per intake channel (REQ-FEE-01)

A fee SHALL be published against a case type as a shillinq fee schedule,
carrying one amount per intake channel and the article of the
legesverordening the amount rests on. Raising the leges for a case SHALL
resolve the amount from that schedule for the case's own intake channel;
dossiq SHALL NOT declare an amount of its own. A schedule with no citation
SHALL be refused, and a case type with no published fee SHALL raise
nothing.

> Amended while building. The proposal put the fee list on the caseType.
> shillinq#1637 shipped the schedule first, keyed by register, schema and
> type value, with `amounts` per channel and a structured `legalBasis` it
> refuses to publish without. A second list of amounts here would be the
> two-sources-of-truth this change's own D-2 forbids for money, so dossiq
> consumes the schedule instead of restating it.

#### Scenario: the balie and the portal cost different amounts
@e2e tests/e2e/fees-and-payments-on-the-case.spec.ts

- **GIVEN** a published schedule with one amount for the portal and another for the balie
- **WHEN** a case is created through each
- **THEN** each SHALL raise a payment request for its own amount

#### Scenario: the citizen can be told which article
@e2e tests/e2e/fees-and-payments-on-the-case.spec.ts

- **GIVEN** a case with a raised payment request
- **WHEN** a handler opens it
- **THEN** the article of the legesverordening SHALL be named

#### Scenario: a fee with no article is refused, not warned

- **GIVEN** a schedule declaring an amount and no citation
- **WHEN** it is published
- **THEN** publication SHALL be refused, naming what is missing

> Amended while building: shillinq refuses it outright
> (`FeeScheduleService::assertLegalBasis`). An amount nobody can trace to a
> council decision cannot be charged, and a warning is a thing that ships.

#### Scenario: a case type with no fee raises nothing
@e2e tests/e2e/fees-and-payments-on-the-case.spec.ts

- **GIVEN** a case type with no published fee
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

Money that arrived another way SHALL be recorded as an act, behind
shillinq's `payment.administer` permission, recording who, when and why,
and performed on the case through shillinq's payment panel. dossiq SHALL
offer no second way to set the state: the projection SHALL be read-only
everywhere it renders, and no dossiq surface SHALL make it an editable
field.

> Amended while building. The proposal gave dossiq its own override act
> behind its own role. shillinq#1637 shipped the settlement as an append
> behind `payment.administer`, so a dossiq act would be a second path to
> one financial fact, with a second rights matrix behind it and two
> records of who said the money arrived.

#### Scenario: cash at the balie is recorded

- **GIVEN** a case with an outstanding payment and a person holding `payment.administer`
- **WHEN** they record the counter payment with a reference
- **THEN** the case's state SHALL read paid at the next read
- **AND** who, when and why SHALL be recorded in shillinq

#### Scenario: dossiq offers no second way to set it

- **GIVEN** the payment state on a case
- **WHEN** any dossiq surface renders it
- **THEN** it SHALL be read-only
- **AND** no dossiq endpoint SHALL write it from a request body

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

- **GIVEN** three cases raised under one contract
- **WHEN** the contract is read
- **THEN** all three SHALL be listed

#### Scenario: dossiq copies no contract term

- **GIVEN** a case referencing a contract
- **WHEN** the dossiq record is read
- **THEN** it SHALL carry the reference
- **AND** it SHALL NOT carry the contract's term, costs or renewal date
