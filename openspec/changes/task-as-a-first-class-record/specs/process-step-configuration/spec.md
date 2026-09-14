## ADDED Requirements

### Requirement: Each task in a case type is configured on its own (REQ-TASK-040)

A case type's workflow declaration SHALL carry a block per task: whether
it runs, its form, its candidate group, its lead time and its effects.
The block SHALL be one declaration per task and SHALL NOT be several
parallel maps keyed by task name. Publishing SHALL refuse a task naming a
form, a group or an effect handler that cannot be resolved, naming what
was missing.

#### Scenario: a task gets its own lead time
@e2e tests/e2e/task-as-a-first-class-record.spec.ts

- **GIVEN** a case type whose hoorzitting task declares 10 days
- **WHEN** the task is created on a case
- **THEN** its due date SHALL be 10 working days out

#### Scenario: a task is switched off for one case type
@e2e tests/e2e/task-as-a-first-class-record.spec.ts

- **GIVEN** two case types sharing a process, one with the advice task off
- **WHEN** a case of each reaches that step
- **THEN** the advice task SHALL exist on one and not the other

#### Scenario: an unresolvable form refuses publication

- **GIVEN** a case type whose task names a form that does not exist
- **WHEN** it is published
- **THEN** publication SHALL refuse
- **AND** it SHALL name the form and the task

### Requirement: Always-available acts are declared beside the phase's acts (REQ-TASK-041)

A case type SHALL declare a list of acts available in every phase, beside
the acts of the current phase. The two lists SHALL be rendered together
and distinguished. An always-available act SHALL NOT appear in the phase
strip, SHALL NOT enter the progress figure, and SHALL NOT be given a phase
term.

#### Scenario: what may I do right now, in two halves
@e2e tests/e2e/task-as-a-first-class-record.spec.ts

- **GIVEN** a case type declaring three always-available acts and a phase with two
- **WHEN** a handler opens the case
- **THEN** five acts SHALL be offered
- **AND** the always-available three SHALL be marked as such

#### Scenario: an always-available act is not a phase

- **GIVEN** a case type with always-available acts
- **WHEN** the phase strip and the progress figure are read
- **THEN** neither SHALL include them

#### Scenario: an act unavailable on this case says why

- **GIVEN** an always-available act refused by a guard
- **WHEN** a handler reads the acts
- **THEN** it SHALL be shown and disabled with the guard's sentence
