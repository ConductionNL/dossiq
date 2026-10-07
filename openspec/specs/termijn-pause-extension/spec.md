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

### Requirement: A request to complete an application is a record on the case (REQ-AVR-01)

A request to an applicant to complete their submission SHALL be written as
an `aanvullingsverzoek` object on the case. It SHALL name the party asked,
the `pauseReason` that types it, the items that are missing, who asked, when
they asked and the date the hersteltermijn ends. A suspended term SHALL NOT
stand in for the record.

#### Scenario: Asking writes the request and suspends the term
@e2e tests/e2e/aanvullingsverzoek-as-a-record.spec.ts

- **GIVEN** a case in behandeling with a running beslistermijn
- **WHEN** a handler asks the applicant for two missing documents
- **THEN** an `aanvullingsverzoek` SHALL be written naming both items, the reason and the hersteltermijn date
- **AND** the term SHALL be suspended through the engine timer, not by a second clock

#### Scenario: The request names who asked
@e2e exclude unit; AanvullingsverzoekServiceTest

- **GIVEN** a request written by a named handler
- **WHEN** the record is read a year later
- **THEN** it SHALL still name the handler, the moment and the reason

### Requirement: The answer says which items arrived (REQ-AVR-02)

Recording an answer SHALL mark each requested item as received or still
missing, SHALL close the request only when the handler says it is complete,
and SHALL resume the term through the existing credit path. A partially
answered request SHALL stay open with its outstanding items named.

#### Scenario: A partial answer leaves the request open
@e2e tests/e2e/aanvullingsverzoek-as-a-record.spec.ts

- **GIVEN** an open request for two items
- **WHEN** one of them arrives and the handler records it
- **THEN** the request SHALL stay open naming the item still missing
- **AND** the term SHALL stay suspended

#### Scenario: A full answer closes the request and resumes the clock
@e2e tests/e2e/aanvullingsverzoek-as-a-record.spec.ts

- **GIVEN** an open request for two items
- **WHEN** both arrive and the handler records the answer as complete
- **THEN** the request SHALL read answered
- **AND** the unused part of the suspension SHALL be credited back as it is today

### Requirement: An unanswered request expires and stays readable (REQ-AVR-03)

A request whose hersteltermijn passes without an answer SHALL become
`expired`. It SHALL remain readable with its items and its dates unchanged,
and nothing SHALL delete or rewrite it.

#### Scenario: The hersteltermijn passes
@e2e exclude time-dependent; unit over the timer-fired listener, AanvullingsverzoekExpiryTest

- **GIVEN** an open request whose hersteltermijn is today
- **WHEN** the day passes without an answer
- **THEN** the request SHALL read expired
- **AND** the items it asked for SHALL still be readable

### Requirement: Cases waiting on an applicant are a list (REQ-AVR-04)

A case with an open `aanvullingsverzoek` SHALL be findable as such. The work
list SHALL filter on it and SHALL show how long the request has been open. A
count of cases waiting on an applicant SHALL come from the requests
themselves.

#### Scenario: The work list answers what we are waiting on
@e2e tests/e2e/aanvullingsverzoek-as-a-record.spec.ts

- **GIVEN** three cases with an open request and twelve without
- **WHEN** a handler filters the work list on waiting for the applicant
- **THEN** exactly the three SHALL be listed
- **AND** each SHALL show the days its request has been open

#### Scenario: Answering removes the case from the filter
@e2e tests/e2e/aanvullingsverzoek-as-a-record.spec.ts

- **GIVEN** a case in that filter
- **WHEN** its request is answered in full
- **THEN** the case SHALL leave the filter

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
