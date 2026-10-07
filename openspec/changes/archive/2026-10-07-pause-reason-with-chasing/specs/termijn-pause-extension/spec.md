## ADDED Requirements

### Requirement: A pause names a reason that chases (REQ-TERM-011)

A `pauseReason` per case type SHALL declare a key, a name, a category
(applicant, third party, internal or statutory), who is waited on, the legal
basis, the reminder interval in days, the reminder text, how many reminders may
go out and whether the interval counts working days. A pause SHALL be
registered under one of the reasons its case type declares, and the free-text
rationale SHALL remain as the note beside it. Each reminder SHALL be sent to
the address the request went to, SHALL be recorded as a `chased` event on the
term and as an entry on the case timeline, and SHALL be counted on the
instance. After the last reminder in the budget the silence SHALL be escalated
once, naming the escalation target the case type declared.

#### Scenario: The applicant is chased on schedule
@e2e exclude time-dependent; a reminder falls due five days after a pause starts, and seeding a pause that already started would seed a row the app never writes. Covered by tests/Unit/Service/Pause/ChaseScheduleTest.php (rung offsets 9 and 4 for a 14-day pause, interval 5, budget 2; working-day and calendar-day intervals) and tests/Unit/Service/Pause/PauseChaseServiceTest.php::testTheFirstReminderIsSentRecordedAndCounted, which drive their own moment.

- **GIVEN** a case paused for 14 days under a reason with interval 5 and budget 2
- **WHEN** the first reminder falls due
- **THEN** the reminder text SHALL be sent to the address the request went to
- **AND** a `chased` event SHALL be recorded on the instance and on the timeline
- **AND** the count on the instance SHALL read one

#### Scenario: The same reminder is never sent twice
@e2e exclude time-dependent; both triggers are driven by a moment. Covered by tests/Unit/Service/Pause/PauseChaseServiceTest.php::testASecondTriggerOnAStaleRowSendsNothing, which is the engine rung and the daily sweep reaching the same instance.

- **GIVEN** a pause whose first reminder has already gone out
- **WHEN** a second trigger arrives carrying the row as it was before
- **THEN** nothing SHALL be sent
- **AND** the count SHALL stay as it was

#### Scenario: The handler hears after the last reminder
@e2e exclude time-dependent; the escalation falls due one interval after the last reminder. Covered by tests/Unit/Service/Pause/PauseChaseServiceTest.php::testTheHandlerHearsAfterTheLastReminder and tests/Unit/Service/Pause/ChaseScheduleTest.php::testTheEscalationWaitsOneIntervalAndHappensOnce.

- **GIVEN** the same pause after two reminders without a reply
- **WHEN** one more interval passes
- **THEN** the case timeline SHALL say no reply came after 2 reminders
- **AND** it SHALL say so once, however long the pause runs on

#### Scenario: A pause is registered under a declared reason
@e2e tests/e2e/pause-reason-with-chasing.spec.ts

- **GIVEN** a case type declaring a pause reason for the applicant
- **WHEN** the applicant is asked for something under that reason
- **THEN** the suspended term SHALL carry the reason key and the party waited on

#### Scenario: A reason the case type does not declare is refused
@e2e tests/e2e/pause-reason-with-chasing.spec.ts

- **GIVEN** the same case type
- **WHEN** a pause names a reason it does not declare
- **THEN** the request SHALL be refused
- **AND** the term SHALL still be running

#### Scenario: Resume stops the chasing
@e2e tests/e2e/pause-reason-with-chasing.spec.ts

- **GIVEN** a paused case with reminders still to come
- **WHEN** the aanvulling is recorded and the term resumes
- **THEN** no further reminder SHALL be sent
- **AND** the term SHALL carry no reason and no reminder count

### Requirement: The queue says who a case waits on and what has been tried (REQ-TERM-012)

The personal queue SHALL show, beside a case that is waiting on somebody, who
it waits on, for how many days, and how many reminders have gone out. A case
nobody is waiting on SHALL show nothing. The case terms panel SHALL show the
reason a suspended clock is suspended for and the reminders sent under it.

#### Scenario: The queue reads the waiting sentence
@e2e exclude the sentence is a pure reading of two stored fields and a count, with no seam between apps in it. Covered by tests/Unit/Service/Queue/AssignedCasesWaitingTest.php for the facts the server computes and tests/vitest/pauseChasingSentences.spec.js for the words the browser chooses, both mutation-checked.

- **GIVEN** a case waiting on the applicant for 9 days, chased twice
- **WHEN** the queue is read
- **THEN** it SHALL say waiting on the applicant for 9 days, chased 2 times

#### Scenario: A case nobody waits on carries no sentence
@e2e exclude same reading, same two unit files: tests/Unit/Service/Queue/AssignedCasesWaitingTest.php::testACaseNobodyWaitsOnCarriesNoSentence and tests/vitest/pauseChasingSentences.spec.js.

- **GIVEN** a case that is ours to move
- **WHEN** the queue is read
- **THEN** no waiting sentence SHALL be shown beside it
