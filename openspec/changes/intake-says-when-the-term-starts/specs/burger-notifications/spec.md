## ADDED Requirements

### Requirement: A case records when it arrived and when its clock starts (REQ-TERM-040)

A case SHALL record `receivedAt`, the moment the submission arrived, and
`termStartsAt`, the first working moment on or after it according to the
engine calendar. It SHALL record `receivedOutsideWorkingHours` as true
when the two differ. All three SHALL be written at creation and SHALL NOT
be recomputed on read.

#### Scenario: a Sunday filing starts on Monday
@e2e tests/e2e/intake-says-when-the-term-starts.spec.ts

- **GIVEN** a working calendar whose week runs Monday to Friday
- **WHEN** a request arrives on Sunday evening
- **THEN** `receivedAt` SHALL be that Sunday evening
- **AND** `termStartsAt` SHALL be the first working moment of Monday
- **AND** `receivedOutsideWorkingHours` SHALL be true

#### Scenario: a filing inside the working window starts at once
@e2e tests/e2e/intake-says-when-the-term-starts.spec.ts

- **GIVEN** the same calendar
- **WHEN** a request arrives on Tuesday at ten
- **THEN** `receivedAt` and `termStartsAt` SHALL be the same moment
- **AND** `receivedOutsideWorkingHours` SHALL be false

#### Scenario: the stamp does not move afterwards

- **GIVEN** a case filed on a day the calendar later declares a holiday
- **WHEN** the case is read again
- **THEN** `termStartsAt` SHALL still be the moment written at creation

### Requirement: The intake confirmation says when the clock starts (REQ-TERM-041)

The confirmation shown after a submission, and the ontvangstbevestiging
sent for it, SHALL each name the case reference, the moment the request
was received, the moment the term starts and the deadline. When
`receivedOutsideWorkingHours` is true, both SHALL add one sentence saying
the term starts on the first working day. Both SHALL ship in Dutch and
English.

#### Scenario: the citizen is told on screen
@e2e tests/e2e/intake-says-when-the-term-starts.spec.ts

- **GIVEN** a request filed on Sunday evening
- **WHEN** the confirmation appears
- **THEN** it SHALL name the case reference, the Sunday it was received, the Monday the term starts and the deadline
- **AND** it SHALL carry the sentence about the first working day

#### Scenario: the mail says the same thing
@e2e exclude no HTTP trigger; the ontvangstbevestiging is sent by AcknowledgementDispatchJob, a background job, so there is nothing for a browser to press. Asserted in tests/Unit/Service/IntakeConfirmationTextTest.php, in both languages

- **GIVEN** the same request
- **WHEN** the ontvangstbevestiging is sent
- **THEN** it SHALL name the same four
- **AND** the start it names SHALL be the one stored on the case

#### Scenario: no explanation when none is needed
@e2e exclude the same background job; asserted in tests/Unit/Service/IntakeConfirmationTextTest.php::testNoExplanationWhenNoneIsNeeded

- **GIVEN** a request filed on Tuesday at ten
- **WHEN** the confirmation appears
- **THEN** it SHALL name the reference, the received moment, the start and the deadline
- **AND** it SHALL NOT carry the sentence about the first working day
