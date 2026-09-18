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

The ontvangstbevestiging, and the case's own terms panel, SHALL each name
the case reference, the moment the request was received, the moment the
term starts and the deadline.

> Amended while building. The proposal named the confirmation shown
> immediately after submitting. A citizen submits in the PORTAL, which is
> portaliq's surface, and dossiq's own success message is a pre-translated
> toast with no token interpolation in the manifest schema, so the four
> facts cannot go in it without inventing a grammar nextcloud-vue does not
> have. dossiq's half is the stamp, the mail and the case surface; the
> portal confirmation reads the same two fields off the case. When
`receivedOutsideWorkingHours` is true, both SHALL add one sentence saying
the term starts on the first working day. Both SHALL ship in Dutch and
English.

#### Scenario: the handler can see what the citizen was told
@e2e tests/e2e/intake-says-when-the-term-starts.spec.ts

- **GIVEN** a request filed on Sunday evening
- **WHEN** a handler opens the case
- **THEN** the terms panel SHALL name the Sunday it was received, the Monday the term starts and the deadline

#### Scenario: the mail says the same thing
@e2e tests/e2e/intake-says-when-the-term-starts.spec.ts

- **GIVEN** the same request
- **WHEN** the ontvangstbevestiging is sent
- **THEN** it SHALL name the same four
- **AND** the start it names SHALL be the one stored on the case

#### Scenario: no explanation when none is needed
@e2e tests/e2e/intake-says-when-the-term-starts.spec.ts

- **GIVEN** a request filed on Tuesday at ten
- **WHEN** the ontvangstbevestiging is rendered
- **THEN** it SHALL name the reference, the received moment, the start and the deadline
- **AND** it SHALL NOT carry the sentence about the first working day
