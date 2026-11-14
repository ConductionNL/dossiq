## ADDED Requirements

### Requirement: A task reaches a team before it reaches a person (REQ-TASK-042)

A case type SHALL declare candidate groups and candidate users per task. A
task with candidates and no assignee SHALL be offered to every candidate
and SHALL be claimable by one of them, with the claim recorded. Whether the
task engine answers a claim act SHALL be asked of the engine rather than
assumed: where it answers one, the claim affordance SHALL be rendered on
every task waiting for a candidate; where it does not, no affordance SHALL
be rendered and the surface showing the task SHALL say that nobody can pick
it up there yet. dossiq SHALL NOT silently assign a task it presented as
claimable.

#### Scenario: a task sits with a team until somebody takes it
@e2e tests/e2e/task-as-a-first-class-record.spec.ts

- **GIVEN** a task declaring the candidate group Juridische Zaken
- **WHEN** the task is created
- **THEN** it SHALL have no assignee
- **AND** it SHALL be listed for every member of that group

#### Scenario: claiming is recorded
@e2e tests/e2e/task-as-a-first-class-record.spec.ts

- **GIVEN** an unclaimed task with candidates
- **WHEN** a member claims it
- **THEN** they SHALL be its assignee
- **AND** the claim SHALL record who and when

#### Scenario: an unhonoured declaration is stated, not faked
@e2e exclude The engine on the e2e instance answers a claim act, so the unhonoured branch cannot be produced there. Asserted in tests/Unit/Service/Task/TaskCandidatesTest.php::testAnEngineWithoutAClaimActSaysSoRatherThanAssigning.

- **GIVEN** a task engine that answers no claim act
- **WHEN** a handler opens a case carrying a task with a candidate group
- **THEN** the task SHALL name the group it is meant for
- **AND** it SHALL say that nobody can pick it up there yet
- **AND** no claim affordance SHALL be rendered

### Requirement: A task type declares what completing it does (REQ-TASK-043)

A task SHALL declare its effects as named handlers from the action
registry. Completing the task SHALL run them. Publishing SHALL refuse a
task naming a handler the registry does not have. Completing SHALL be
refused when a declared handler cannot be resolved, rather than completing
without the effect. Resuming a suspended term SHALL be one of the
available effects.

#### Scenario: finishing the task sends the letter
@e2e exclude Sending needs a mail server the e2e instance does not have, so a green assertion here would prove the handler ran and not that a letter left. Asserted in tests/Unit/Service/Task/TaskEffectsTest.php::testCompletingRunsTheDeclaredEffects.

- **GIVEN** a task declaring a send effect
- **WHEN** a handler completes it
- **THEN** the letter SHALL be sent

#### Scenario: finishing the aanvulling task resumes the term
@e2e exclude Needs a paused statutory term, which takes the whole aanvulling flow to produce. Asserted in tests/Unit/Service/Task/TaskEffectsTest.php and lib/Service/Transitions/ResumeTermHandler.php's own path in tests/Unit/Service/DeadlinePauseExtensionServiceTest.php.

- **GIVEN** a suspended term and a task declaring the resume effect
- **WHEN** the task is completed
- **THEN** the term SHALL resume

#### Scenario: an unresolvable effect refuses the completion
@e2e exclude A handler cannot be unregistered on a running instance. Asserted in tests/Unit/Service/Task/TaskEffectsTest.php::testAnUnresolvableEffectIsNamed.

- **GIVEN** a task whose declared handler cannot be resolved
- **WHEN** a handler completes it
- **THEN** the completion SHALL be refused
- **AND** the refusal SHALL name the handler

#### Scenario: an unknown handler refuses publication
@e2e exclude Publishing a workflow is an admin API call with no surface of its own yet. Asserted in tests/Unit/Service/Task/PerTaskConfigurationTest.php::testAnUnknownEffectRefusesPublication.

- **GIVEN** a case type declaring an effect the registry does not have
- **WHEN** it is published
- **THEN** publication SHALL refuse, naming the handler

### Requirement: A task is completed where you already are (REQ-TASK-044)

Every open task on a case SHALL be completable on the case page without a
route change, not only the first one. A task carrying a form SHALL show
that form in place. A required field left empty SHALL refuse the
completion with a 4xx carrying `{message, error}` naming the field. The
same completion SHALL be offered from the task list once the component
library provides a row action.

#### Scenario: the second open task is completed on the case
@e2e tests/e2e/task-as-a-first-class-record.spec.ts

- **GIVEN** a case with three open tasks
- **WHEN** a handler completes the second
- **THEN** it SHALL complete without leaving the case page

#### Scenario: the form is filled in place
@e2e tests/e2e/task-as-a-first-class-record.spec.ts

- **GIVEN** a hoorzitting task carrying a verslag form
- **WHEN** a handler opens it on the case
- **THEN** the form SHALL be shown there
- **AND** completing it SHALL store the verslag on the task

#### Scenario: a blank required field refuses
@e2e tests/e2e/task-as-a-first-class-record.spec.ts

- **GIVEN** a task form with a required field left empty
- **WHEN** a handler completes the task
- **THEN** it SHALL be refused
- **AND** the refusal SHALL name the field

### Requirement: A file uploaded in a task form binds to the task (REQ-TASK-045)

A file uploaded inside a task form SHALL be held against the task while the
task is open, SHALL be removable while it is open, and SHALL become a
document on the case when the task completes, recording which task
produced it. A file SHALL NOT remain reachable only from a closed task.

#### Scenario: the upload waits with the task
@e2e tests/e2e/task-as-a-first-class-record.spec.ts

- **GIVEN** an open task with a file uploaded in its form
- **WHEN** the case documents are read
- **THEN** the file SHALL NOT yet be among them

#### Scenario: completing the task publishes the file to the case
@e2e tests/e2e/task-as-a-first-class-record.spec.ts

- **GIVEN** the same task
- **WHEN** it is completed
- **THEN** the file SHALL be a document on the case
- **AND** it SHALL record the task it came from

#### Scenario: a file is removed while the task is open
@e2e tests/e2e/task-as-a-first-class-record.spec.ts

- **GIVEN** an open task with an uploaded file
- **WHEN** a handler removes it
- **THEN** it SHALL be gone from the task

### Requirement: A task shows its own number, due date and lock (REQ-TASK-046)

Wherever a task is shown, it SHALL carry its own number, its own due date
and its own lock state. Where the task engine provides no number or no
lock, dossiq SHALL show the engine's identifier and SHALL state that a
number and a lock are not available, and SHALL NOT invent either.

#### Scenario: a task is referred to by its own number
@e2e tests/e2e/task-as-a-first-class-record.spec.ts

- **GIVEN** a task carrying a number from the engine
- **WHEN** it is shown on the case and in the list
- **THEN** both SHALL show that number

#### Scenario: a missing number is stated, not invented
@e2e tests/e2e/task-as-a-first-class-record.spec.ts

- **GIVEN** a task engine answering no number
- **WHEN** the task is shown
- **THEN** the engine identifier SHALL be shown
- **AND** dossiq SHALL NOT generate a number of its own

#### Scenario: the due date is the task's own
@e2e tests/e2e/task-as-a-first-class-record.spec.ts

- **GIVEN** a task with a lead time shorter than the case term
- **WHEN** the task is shown
- **THEN** its due date SHALL be its own, not the case deadline
