## ADDED Requirements

### Requirement: The declared suspension and extension lengths are enforced (REQ-TERM-066)

`caseType` SHALL declare a maximum suspension length in days beside its
existing `suspensionAllowed`, `extensionAllowed` and `extensionPeriod`. A
suspension or an extension longer than the declared length SHALL be
refused with a 4xx carrying `{message, error}`, the rule named in `error`.
`extensionPeriod` SHALL be read by the service that moves the deadline and
SHALL NOT be read only by the ZGW mapping.

#### Scenario: an extension beyond the declared period is refused
@e2e tests/e2e/phase-terms-and-the-internal-target.spec.ts

- **GIVEN** a case type declaring an extension period of 42 days
- **WHEN** a handler extends the term by 60 days
- **THEN** it SHALL be refused with a 4xx
- **AND** the response SHALL name the rule and the declared period

#### Scenario: an extension within the period is allowed
@e2e tests/e2e/phase-terms-and-the-internal-target.spec.ts

- **GIVEN** a case type declaring an extension period of 42 days
- **WHEN** a handler extends the term by 30 days
- **THEN** the term SHALL move by 30 working days

#### Scenario: a suspension beyond the declared length is refused

- **GIVEN** a case type declaring a maximum suspension of 28 days
- **WHEN** a handler suspends for 60 days
- **THEN** it SHALL be refused
- **AND** the refusal SHALL name the maximum

#### Scenario: a case type that allows neither refuses both

- **GIVEN** a case type with `suspensionAllowed` and `extensionAllowed` false
- **WHEN** either is attempted
- **THEN** each SHALL be refused with the rule named

### Requirement: Asking the applicant and suspending the term are one act (REQ-TERM-067)

Requesting information from the applicant SHALL send the request, record
what was asked for, and suspend the term, as one act with one record, per
Awb 4:5. If the request fails to send, the term SHALL NOT be suspended.
Receiving the aanvulling SHALL resume the term as one act and SHALL record
what was received.

#### Scenario: the letter and the pause happen together
@e2e tests/e2e/phase-terms-and-the-internal-target.spec.ts

- **GIVEN** a case with a running statutory term
- **WHEN** a handler requests missing information
- **THEN** the request SHALL be sent
- **AND** the term SHALL be suspended
- **AND** one record SHALL carry what was asked, when, and the suspension

#### Scenario: a failed letter leaves the clock running
@e2e tests/e2e/phase-terms-and-the-internal-target.spec.ts

- **GIVEN** an unreachable transport
- **WHEN** a handler requests missing information
- **THEN** the term SHALL NOT be suspended
- **AND** the failure SHALL be visible on the case

#### Scenario: receiving the aanvulling resumes the term
@e2e tests/e2e/phase-terms-and-the-internal-target.spec.ts

- **GIVEN** a suspended term and a recorded request
- **WHEN** the aanvulling is received
- **THEN** the term SHALL resume
- **AND** the record SHALL carry what was received and when
