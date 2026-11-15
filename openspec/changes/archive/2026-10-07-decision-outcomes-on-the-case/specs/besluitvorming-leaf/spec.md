## ADDED Requirements

### Requirement: A case is gated by the approval outcome decidiq walks (REQ-DEC-01)

A case type SHALL declare which acts require a walked approval before they
may be performed. While an approval is outstanding, those acts SHALL be
refused with a 4xx carrying `{message, error}` naming the approval, and the
case SHALL show what it is waiting for and on whom. dossiq SHALL read the
outcome from decidiq and SHALL NOT compute an approval outcome of its own.

#### Scenario: the case waits for the signatures
@e2e tests/e2e/decision-outcomes-on-the-case.spec.ts

- **GIVEN** a case type requiring an approval before the besluit is sent
- **WHEN** a handler sends the besluit with the approval outstanding
- **THEN** it SHALL be refused
- **AND** the refusal SHALL name the approval

#### Scenario: the case says who it is waiting for
@e2e tests/e2e/decision-outcomes-on-the-case.spec.ts

- **GIVEN** an outstanding approval with two named approvers
- **WHEN** a handler opens the case
- **THEN** it SHALL name both and what is waiting on them

#### Scenario: an approved case proceeds
@e2e tests/e2e/decision-outcomes-on-the-case.spec.ts

- **GIVEN** an approval decidiq reports as granted
- **WHEN** a handler sends the besluit
- **THEN** it SHALL be sent

#### Scenario: an unreadable outcome blocks rather than passes

- **GIVEN** a case whose approval outcome dossiq cannot read
- **WHEN** a handler performs the gated act
- **THEN** it SHALL be refused
- **AND** the refusal SHALL say the approval service is unavailable

### Requirement: An inadmissible verdict ends the case at intake (REQ-DEC-02)

A case type SHALL be able to declare that intake ends with an admissibility
judgement. An inadmissible verdict SHALL close the case through the
ordinary close act, with a result of niet-ontvankelijk, recording who
judged it and when, and SHALL tell the applicant through the case type's
declared moments. An admissible verdict SHALL move the case on.

#### Scenario: an inadmissible aanvraag does not sit in intake
@e2e tests/e2e/decision-outcomes-on-the-case.spec.ts

- **GIVEN** a case type declaring an admissibility judgement at intake
- **WHEN** an intake worker judges a case inadmissible
- **THEN** the case SHALL close with that result
- **AND** the judge SHALL be recorded

#### Scenario: the applicant is told
@e2e tests/e2e/decision-outcomes-on-the-case.spec.ts

- **GIVEN** a case closed as inadmissible
- **WHEN** the close completes
- **THEN** the applicant SHALL be told through the declared moment

#### Scenario: the close inherits the retention rule

- **GIVEN** a case closed as inadmissible
- **WHEN** its retention is read
- **THEN** it SHALL come from that result type
