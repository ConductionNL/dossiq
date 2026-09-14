## ADDED Requirements

### Requirement: A status may declare what makes it true (REQ-SDC-01)

A `statusType` SHALL be able to declare the conditions under which it holds.
Where it does, the case SHALL move into that status as the conditions become
true, and the status SHALL NOT be offered as a transition a person picks. A
status that declares no conditions SHALL keep being reached by a chosen
transition, as it is today.

#### Scenario: The case becomes complete when the file is complete
@e2e tests/e2e/what-a-status-declares.spec.ts

- **GIVEN** a case type whose status Complete declares the intake form and four documents
- **WHEN** the fourth document is added
- **THEN** the case SHALL move to Complete without anybody choosing it

#### Scenario: A derived status is not offered as a choice
@e2e tests/e2e/what-a-status-declares.spec.ts

- **GIVEN** the same case type
- **WHEN** a handler opens the transition list
- **THEN** Complete SHALL NOT be offered
- **AND** the statuses that declare no conditions SHALL still be offered

#### Scenario: An unmet derivation says what is missing
@e2e tests/e2e/what-a-status-declares.spec.ts

- **GIVEN** a case with three of the four documents
- **WHEN** the handler opens it
- **THEN** the case SHALL name the missing document as the reason it is not Complete

### Requirement: A status declares who the case is waiting on (REQ-SDC-02)

A `statusType` SHALL declare whether the case waits on us, on the applicant
or on a named third party. Waiting on the applicant and waiting on a third
party SHALL be distinct values. Queue and team counts SHALL be built on that
declaration. The existing `role` values SHALL keep working unchanged.

#### Scenario: Two waiting statuses are told apart
@e2e tests/e2e/what-a-status-declares.spec.ts

- **GIVEN** a status Waiting for the applicant and a status Waiting for advice
- **WHEN** both are read
- **THEN** the first SHALL declare the applicant and the second a third party

#### Scenario: A team sees what is theirs to move
@e2e tests/e2e/what-a-status-declares.spec.ts

- **GIVEN** a queue of forty cases, twelve waiting on the applicant and six on a third party
- **WHEN** the queue is counted by who is waited on
- **THEN** twenty-two SHALL be reported as ours to move
- **AND** the other two counts SHALL be reported separately

#### Scenario: The shipped flow keeps reading role
@e2e exclude unit over the lookup; StatusWaitingOnTest

- **GIVEN** a status whose `role` is `pending-info`
- **WHEN** the shipped flow reads it
- **THEN** it SHALL behave exactly as it does today

### Requirement: A status may declare a maximum dwell that breaches on its own (REQ-SDC-03)

A `statusType` SHALL be able to declare a maximum dwell, counted on the
organisation's working calendar. Entering the status SHALL arm a timer and
leaving it SHALL cancel the timer. A breach SHALL be its own event with its
own notification and its own filter, and SHALL NOT change the case's
statutory term or its state.

#### Scenario: Nine weeks in a status inside a healthy term
@e2e tests/e2e/what-a-status-declares.spec.ts

- **GIVEN** a case with eight weeks left on its term, in a status whose maximum is four weeks
- **WHEN** the fifth week in that status begins
- **THEN** the status dwell SHALL be reported as breached
- **AND** the case term SHALL still read as not breached

#### Scenario: Leaving the status cancels the timer
@e2e exclude unit; StatusDwellTimerTest

- **GIVEN** a case in a status with a declared maximum
- **WHEN** it moves to the next status before the maximum
- **THEN** the timer SHALL be cancelled
- **AND** no breach SHALL be recorded

#### Scenario: The maximum counts working days
@e2e exclude unit fixture pair over the seeded calendar; StatusDwellTimerTest

- **GIVEN** a status with a maximum of five days entered on a Friday
- **WHEN** the weekend and a general holiday fall inside the window
- **THEN** the breach SHALL be due five working days later, not five calendar days
