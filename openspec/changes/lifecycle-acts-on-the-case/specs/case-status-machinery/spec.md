## ADDED Requirements

### Requirement: A hidden status drops its cases out of the working list (REQ-LIFE-01)

`statusType.hiddenInLists` SHALL be read by the working list, the open
counts and the dashboard tiles, and cases in a hidden status SHALL NOT
appear in them. A hidden status SHALL NOT remove a case from search, from
its own page, or from a list a person explicitly filtered onto that
status.

#### Scenario: a completed case leaves the working list without a filter
@e2e tests/e2e/lifecycle-acts-on-the-case.spec.ts

- **GIVEN** a status with `hiddenInLists` true holding three cases
- **WHEN** a handler opens the working list
- **THEN** those three cases SHALL NOT be listed

#### Scenario: the counts agree with the list
@e2e tests/e2e/lifecycle-acts-on-the-case.spec.ts

- **GIVEN** cases in a hidden status
- **WHEN** the open count is read
- **THEN** they SHALL NOT be counted

#### Scenario: a hidden case is still findable
@e2e tests/e2e/lifecycle-acts-on-the-case.spec.ts

- **GIVEN** a case in a hidden status
- **WHEN** a handler searches for its number
- **THEN** it SHALL be found
- **AND** its page SHALL open

#### Scenario: asking for the status shows them

- **GIVEN** a hidden status
- **WHEN** a handler filters the list onto that status
- **THEN** its cases SHALL be listed

### Requirement: Every declared display flag has a reader (REQ-LIFE-02)

Every display flag declared on a dossiq schema SHALL be read by at least
one query, store or component, or SHALL carry an entry in a reason-bearing
allowlist. A structural test SHALL fail when a declared flag has no reader
and no allowlist entry, and SHALL fail when an allowlisted flag gains a
reader without its entry being removed.

#### Scenario: a control nobody reads fails the build

- **GIVEN** a new display flag on a schema
- **WHEN** nothing reads it and it is not allowlisted
- **THEN** the structural test SHALL fail
- **AND** it SHALL name the flag and the schema

#### Scenario: an allowlisted flag that gained a reader is caught

- **GIVEN** an allowlisted flag
- **WHEN** a reader is added and the entry is left
- **THEN** the structural test SHALL fail

### Requirement: A case type may give its status to the process (REQ-LIFE-03)

A case type SHALL declare whether its status is owned by the process. Where
it is, a status SHALL only be set through a transition, and a direct write
of the status field SHALL be refused with a 4xx carrying `{message, error}`
naming the rule. A case type declaring process ownership whose process
cannot be resolved SHALL refuse the direct write rather than allow it.

#### Scenario: a hand-set status is refused where the process owns it
@e2e tests/e2e/lifecycle-acts-on-the-case.spec.ts

- **GIVEN** a case type declaring process-owned status
- **WHEN** a handler writes the status field directly
- **THEN** it SHALL be refused
- **AND** the refusal SHALL name the rule

#### Scenario: the transition still moves it
@e2e tests/e2e/lifecycle-acts-on-the-case.spec.ts

- **GIVEN** the same case type
- **WHEN** a handler performs a declared transition
- **THEN** the status SHALL move

#### Scenario: a case type that does not declare it keeps today's behaviour

- **GIVEN** a case type without the declaration
- **WHEN** a handler sets the status
- **THEN** it SHALL be accepted

#### Scenario: an unresolvable process fails closed

- **GIVEN** a case type declaring process-owned status whose process is missing
- **WHEN** a handler writes the status directly
- **THEN** it SHALL be refused

### Requirement: A case type may close a case after declared silence (REQ-LIFE-04)

A case type MAY declare a period of no activity after which dossiq closes
the case. The default SHALL be off. Before the close, the applicant SHALL
be told it is coming, through the declared automatic moments. The close
SHALL record that the product performed it, the period declared, and the
last activity it counted from.

#### Scenario: a bezwaar waiting on the indiener does not sit open forever
@e2e tests/e2e/lifecycle-acts-on-the-case.spec.ts

- **GIVEN** a case type declaring 60 days of silence
- **WHEN** a case has had no activity for 60 days
- **THEN** it SHALL be closed
- **AND** the close SHALL record that the product did it

#### Scenario: the applicant is warned first
@e2e tests/e2e/lifecycle-acts-on-the-case.spec.ts

- **GIVEN** a case approaching its declared silence period
- **WHEN** the warning moment is reached
- **THEN** the applicant SHALL be told the case will close

#### Scenario: off by default

- **GIVEN** a case type with no declaration
- **WHEN** a case has had no activity for a year
- **THEN** it SHALL NOT be closed
