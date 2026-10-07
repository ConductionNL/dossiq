## ADDED Requirements

### Requirement: The remedy clause is declared on the case type and printed (REQ-DEC-03)

A case type SHALL declare the remedy open against its decisions: the kind,
the term in days, and the body it is lodged with. The decision document
SHALL print that clause from the declaration and SHALL NOT take it from a
document template. Publishing a case type whose decisions carry no remedy
declaration SHALL warn, naming the case type.

#### Scenario: a besluit carries its bezwaarclausule
@e2e tests/e2e/decision-outcomes-on-the-case.spec.ts

- **GIVEN** a case type declaring bezwaar, 42 days, at the college
- **WHEN** a besluit is generated
- **THEN** the document SHALL print that kind, that term and that body

#### Scenario: a change in the law is one configuration change
@e2e tests/e2e/decision-outcomes-on-the-case.spec.ts

- **GIVEN** two case types sharing a document template
- **WHEN** one declares a different remedy term
- **THEN** its decisions SHALL print the new term
- **AND** the other's SHALL be unchanged

#### Scenario: a missing declaration is warned about

- **GIVEN** a case type whose decisions declare no remedy
- **WHEN** it is published
- **THEN** publication SHALL warn, naming the case type

### Requirement: Sending a decision starts the remedy term (REQ-DEC-04)

Sending a decision SHALL bind a term instance of kind `remedy`, from the
case type's declared remedy term, clocked on the administered working
calendar. Whether a decision is still open to a remedy SHALL be answerable
from the case without arithmetic.

#### Scenario: the bezwaartermijn starts when the besluit goes out
@e2e tests/e2e/decision-outcomes-on-the-case.spec.ts

- **GIVEN** a case type declaring a remedy term of 42 days
- **WHEN** the besluit is sent
- **THEN** a remedy term SHALL be bound, ending 42 working days later

#### Scenario: is this still open to bezwaar
@e2e tests/e2e/decision-outcomes-on-the-case.spec.ts

- **GIVEN** a decision sent 50 days ago with a 42 day remedy term
- **WHEN** a handler opens the case
- **THEN** it SHALL read that the remedy term has expired
