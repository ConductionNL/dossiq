## ADDED Requirements

### Requirement: A case records when it arrived and when its clock starts (REQ-TERM-040)

A case SHALL record `receivedAt`, the moment the submission arrived, and
`termStartsAt`, the first working moment on or after it according to the
engine calendar. It SHALL record `receivedOutsideWorkingHours` as true
when the two differ. All three SHALL be written at creation and SHALL NOT
be recomputed on read.

When the engine calendar does not answer, `termStartsAt` and the flag SHALL be
left unset and `receivedAt` SHALL still be stamped. dossiq keeps no calendar of
its own, so a start it cannot get from the engine is left unsaid rather than
guessed from a weekday rule: a guessed start is quoted back by an applicant
months later and there is no defence for it.

A case that already carries `receivedAt` SHALL NOT be stamped again, including
one imported carrying another system's `receivedAt`. The request arrived when it
arrived, whatever day dossiq first saw the record.

The two moments SHALL be compared as instants and not as formatted strings: the
same moment written in another timezone formats differently and means the same
thing, and a string comparison would flag a Tuesday morning filing as out of
hours.

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
@e2e tests/e2e/intake-says-when-the-term-starts.spec.ts

- **GIVEN** a case filed on a day the calendar later declares a holiday
- **WHEN** the case is read again
- **THEN** `termStartsAt` SHALL still be the moment written at creation

### Requirement: The intake confirmation says when the clock starts (REQ-TERM-041)

The confirmation shown after a submission, and the ontvangstbevestiging
sent for it, SHALL each name the case reference, the moment the request
was received, the moment the term starts and the deadline. When
`receivedOutsideWorkingHours` is true AND a `termStartsAt` is stored, both SHALL
add one sentence saying the term starts on the first working day. Both SHALL
ship in Dutch and English.

The sentence SHALL NOT appear when no `termStartsAt` is stored, even with the
flag true: a sentence promising a start the confirmation does not name is worse
than no sentence.

Both surfaces SHALL read the STORED moments and recompute nothing, and SHALL
take them from one producer. A confirmation that disagreed with the mail sent
ten seconds later would be worse than either alone.

The confirmation endpoint SHALL refuse a caller who may not read the case.

#### Scenario: the citizen is told on screen
@e2e tests/e2e/intake-says-when-the-term-starts.spec.ts

- **GIVEN** a request filed on Sunday evening
- **WHEN** the confirmation appears
- **THEN** it SHALL name the case reference, the Sunday it was received, the Monday the term starts and the deadline
- **AND** it SHALL carry the sentence about the first working day

#### Scenario: the mail says the same thing
@e2e exclude no mail is sent on the e2e rig, and the template's rendering is the library's; the shared producer is asserted in `tests/Unit/Service/IntakeTermStartTest.php::testThePlaceholdersMatchTheScreen`, which holds the mail placeholders to the screen's own values

- **GIVEN** the same request
- **WHEN** the ontvangstbevestiging is sent
- **THEN** it SHALL name the same four
- **AND** the start it names SHALL be the one stored on the case

#### Scenario: no explanation when none is needed
@e2e tests/e2e/intake-says-when-the-term-starts.spec.ts

- **GIVEN** a request filed on Tuesday at ten
- **WHEN** the confirmation appears
- **THEN** it SHALL name the reference, the received moment, the start and the deadline
- **AND** it SHALL NOT carry the sentence about the first working day
