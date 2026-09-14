## ADDED Requirements

### Requirement: A case type declares a first-response term, and the overrun is stored (REQ-TCF-01)

A case type SHALL be able to declare a first-response term as an ordinary
term definition. The outcome SHALL be recorded on the case whether it was met
or missed, and a miss SHALL store the size of the overrun in the term's own
counting mode. A flag alone SHALL NOT stand in for the size.

#### Scenario: A missed first response stores how late it was
@e2e tests/e2e/term-configuration-beyond-the-case-type.spec.ts

- **GIVEN** a case type with a first-response term of three working days
- **WHEN** the first response is sent on the sixth working day
- **THEN** the case SHALL record the term as missed
- **AND** the overrun SHALL be stored as three working days

#### Scenario: A met first response is recorded too
@e2e tests/e2e/term-configuration-beyond-the-case-type.spec.ts

- **GIVEN** the same case type
- **WHEN** the first response is sent on the second working day
- **THEN** the case SHALL record the term as met with no overrun

#### Scenario: The complaint acknowledgement keeps its dates
@e2e exclude unit, behaviour parity over the existing service; ComplaintServiceTest

- **GIVEN** a complaint case
- **WHEN** its acknowledgement deadline is computed from the declaration
- **THEN** the date SHALL be the one the private constant produces today

#### Scenario: The overrun is reportable
@e2e exclude unit over the reader; FirstResponseOutcomeTest

- **GIVEN** eleven missed first responses across a quarter
- **WHEN** the overrun is reported per case type
- **THEN** the count and the average overrun SHALL be returned from the stored numbers

### Requirement: A term resolves from the organisation, the service and the priority (REQ-TCF-02)

A term SHALL resolve in a declared order: the organisation, then the
service, then the priority, falling back to the case type's own term. One
case type SHALL therefore carry different terms for different participating
organisations without being duplicated. The resolution that produced a
case's term SHALL be recorded on the case.

#### Scenario: One case type, two municipal norms
@e2e tests/e2e/term-configuration-beyond-the-case-type.spec.ts

- **GIVEN** one case type shared by two municipalities with different agreed norms
- **WHEN** a case is created for each
- **THEN** each SHALL receive its own municipality's term

#### Scenario: The fallback is the case type
@e2e exclude unit; TermResolutionTest

- **GIVEN** an organisation that declares no term for a case type
- **WHEN** a case is created there
- **THEN** the case type's own term SHALL be used

#### Scenario: A disputed date can be explained
@e2e tests/e2e/term-configuration-beyond-the-case-type.spec.ts

- **GIVEN** a case whose term was resolved a year ago
- **WHEN** the term is read
- **THEN** the case SHALL name which resolution produced it
- **AND** the explanation SHALL not depend on the configuration as it stands now

#### Scenario: Priority resolves a shorter lead time
@e2e exclude unit; TermResolutionTest

- **GIVEN** a service declaring a shorter term for urgent cases
- **WHEN** a case derives the priority urgent through REQ-PRI-02
- **THEN** the shorter term SHALL be resolved
- **AND** no second priority field SHALL be read

### Requirement: A term declares the statuses its clock runs in, with thresholds as days or shares (REQ-TCF-03)

A term SHALL be able to declare the statuses in which its clock runs.
Entering a status outside that set SHALL suspend the engine timer and leaving
it SHALL resume, through the platform's own suspend and resume. An
escalation threshold SHALL be expressible as a number of days or as a share
of the term, and a share SHALL be resolved to a date when the timer is armed.

#### Scenario: The clock stops while the case sits with an adviser
@e2e tests/e2e/term-configuration-beyond-the-case-type.spec.ts

- **GIVEN** a term declaring that its clock runs only in the handling statuses
- **WHEN** the case moves to a status outside that set for ten working days
- **THEN** the timer SHALL be suspended for those ten days
- **AND** the end date SHALL move by the same amount on resuming

#### Scenario: A quarter of the term means two different dates
@e2e exclude unit fixture pair; ThresholdShareTest

- **GIVEN** a threshold declared at 25 per cent of the term
- **WHEN** it is armed on a six-week term and on a twenty-six-week term
- **THEN** the two dates SHALL differ
- **AND** both SHALL be a quarter of their own term

#### Scenario: An extension re-resolves the shares
@e2e exclude unit; ThresholdShareTest

- **GIVEN** a term with a threshold declared as a share
- **WHEN** the term is extended under Awb 4:14
- **THEN** the timer SHALL be re-armed
- **AND** the threshold date SHALL be recomputed from the new term

#### Scenario: A ladder may mix days and shares
@e2e exclude unit; ThresholdShareTest

- **GIVEN** a ladder with a threshold at 50 per cent and one at two days before the end
- **WHEN** the timer is armed
- **THEN** both rungs SHALL be armed on the engine ladder
- **AND** no second escalation mechanism SHALL be introduced
