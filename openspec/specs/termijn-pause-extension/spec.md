---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# termijn-pause-extension Specification

## Purpose
Manages hersteltermijn pauses and statutory extensions on a running TermijnInstance, pausing the deadline when an aanvraag is incomplete (AWB 4:5/4:15) and adding back only the unconsumed pause days on resume. It allows exactly one motivated extension (AWB 4:14), blocking a second unless an exceptional grond is supplied with supervisor approval, and records each change as a termijn event.

## Requirements

### Requirement: Pauze wegens onvolledige aanvraag (REQ-TERM-002)

The system SHALL provide a hersteltermijn pause (AWB 4:5/4:15) that preserves the original deadline window, resuming with only the unconsumed pause days added back.

#### Scenario: Pauze registration extends the deadline

- **GIVEN** a `TermijnInstance` is running with `einddatumActueel` = 2026-07-27
- **WHEN** the handler registers a hersteltermijn-verzoek with `duurDagen` = 14
- **THEN** `status` SHALL be `gepauzeerd`
- **AND** `einddatumActueel` SHALL extend by 14 days to 2026-08-10
- **AND** a `TermijnGebeurtenis` of type `pauze` SHALL be recorded with `dagenImpact` = +14

#### Scenario: Resume consumes only elapsed pause days

- **GIVEN** a 14-day pauze was registered and the burger responds with aanvulling after 8 days
- **WHEN** the handler registers the aanvulling-ontvangst
- **THEN** a `TermijnGebeurtenis` of type `hervat` SHALL be recorded and `status` SHALL revert to `lopend`
- **AND** `einddatumActueel` SHALL be recalculated adding only the 6 unconsumed pause days (original + 6), not the full 14

### Requirement: Verlenging volgens AWB 4:14 (REQ-TERM-003)

The system SHALL allow exactly one motivated extension and SHALL block a second extension unless an exceptional grond is supplied with supervisor approval.

#### Scenario: First extension with valid motivering succeeds

- **GIVEN** a `TermijnInstance` is running and `aantalVerlengingen` = 0
- **WHEN** the handler requests an extension with a non-empty `motivering` and a `newEinddatum` after the current `einddatumActueel`
- **THEN** the extension SHALL be applied: `aantalVerlengingen` becomes 1, `einddatumActueel` = `newEinddatum`
- **AND** a `TermijnGebeurtenis` of type `verleng` SHALL be recorded with the motivering and `dagenImpact`
- **AND** a verlengingsbrief notification trigger SHALL be emitted

#### Scenario: Second extension is blocked

- **GIVEN** a `TermijnInstance` already has `aantalVerlengingen` = 1
- **WHEN** the handler attempts a second extension
- **THEN** the system SHALL reject it with an error citing AWB 4:14 lid 3
- **AND** the only path forward SHALL be a supervisor-approved exceptional-grond override recorded in a separate audit trail

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
