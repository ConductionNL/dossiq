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

### Requirement: A status declares which fields it requires, hides and locks (REQ-SDC-04)

A `statusType` SHALL be able to declare, per case field, that the field is
required, hidden or read only while the case sits in that status. Each
declaration MAY name the groups it applies to, and a declaration that names
none SHALL apply to everyone. Each declaration MAY carry a condition in the
same vocabulary `derivedWhen` uses, and SHALL apply only where that condition
holds. Each declaration MAY carry the sentence a refusal shows.

Publishing the case type SHALL write those declarations onto the case
schema's `x-openregister-lifecycle.states.<statusType>.fields`, so
OpenRegister decides and refuses. dossiq SHALL NOT evaluate the rules a
second time, and SHALL NOT filter a field of its own.

A property that declares `requiredAtStatus` SHALL be published as a
`required` declaration of that status, so the one control that already exists
starts being enforced rather than gaining a rival.

#### Scenario: A status makes a field required
@e2e tests/e2e/case-types-declare-field-rules.spec.ts

- **GIVEN** a status Besluitvorming that declares the motivation required
- **WHEN** the case type is published
- **THEN** the case schema SHALL carry that status as a state requiring the motivation

#### Scenario: Saving without the field is refused by the platform
@e2e tests/e2e/case-types-declare-field-rules.spec.ts

- **GIVEN** a case in Besluitvorming with no motivation
- **WHEN** a handler saves it
- **THEN** the save SHALL be refused with `state-field-required`
- **AND** the case page SHALL show the sentence the status declared

#### Scenario: A rule that names groups leaves the others alone
@e2e exclude unit over the projection; CaseStateFieldRuleProjectorTest

- **GIVEN** a status that locks the confidentiality for `dossiq-handlers`
- **WHEN** the declarations are published
- **THEN** the published `readOnly` entry SHALL name that group
- **AND** a declaration naming no group SHALL be published without one

#### Scenario: A conditional rule is published as a condition, not as a second status
@e2e exclude unit over the translation; StatusFieldRuleDeclarationTest

- **GIVEN** a rule requiring the motivation only when the decision is a refusal
- **WHEN** the declarations are published
- **THEN** the published entry SHALL carry a `when` reading that field
- **AND** no second status SHALL be created for the branch

#### Scenario: A field required from a status keeps being required
@e2e exclude unit over the projection; CaseStateFieldRuleProjectorTest

- **GIVEN** a property declaring `requiredAtStatus` of Besluitvorming
- **WHEN** the case type is published
- **THEN** that property SHALL be published as required in that status

#### Scenario: The case page reads the decision rather than making one
@e2e tests/e2e/case-types-declare-field-rules.spec.ts

- **GIVEN** a case whose read carries `@self.fieldRules`
- **WHEN** the case page renders
- **THEN** it SHALL name what this status requires, hides and locks from that answer alone

### Requirement: The rules of a case type are listed and tried in the editor (REQ-SDC-05)

The case-type editor SHALL list the rules OpenRegister holds for the case
schema, read from the rules inventory in evaluation order, labelled with the
kind vocabulary the engine publishes rather than a list dossiq keeps. It
SHALL let an administrator try a rule against one case and show the verdict,
the operand and the value that decided. It SHALL key on a rule's id and never
on its position.

The DMN decision tables SHALL keep working unchanged, as the neighbour of the
`flow` kind rather than as something this replaces.

#### Scenario: The rules of the case schema are listed
@e2e tests/e2e/case-types-declare-field-rules.spec.ts

- **GIVEN** a case type whose statuses declare field rules
- **WHEN** an administrator opens the Rules tab
- **THEN** the rules SHALL be listed in the order the engine evaluates them

#### Scenario: A kind dossiq has never seen still renders
@e2e exclude unit over the vocabulary read; caseTypeRules.spec.js

- **GIVEN** a vocabulary carrying a kind this release predates
- **WHEN** the tab renders a rule of that kind
- **THEN** it SHALL render the kind the vocabulary published

#### Scenario: Trying a rule says what decided
@e2e tests/e2e/case-types-declare-field-rules.spec.ts

- **GIVEN** a rule and a case
- **WHEN** the administrator tries the rule against the case
- **THEN** the verdict SHALL be shown with the operand and the value it read
- **AND** nothing SHALL be written to the case
