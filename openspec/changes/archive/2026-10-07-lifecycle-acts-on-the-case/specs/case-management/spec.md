## ADDED Requirements

### Requirement: One menu holds every lifecycle act (REQ-LIFE-10)

Every lifecycle act on a case SHALL be reached from one menu on the case
page, drawn from the acts the lifecycle provider publishes. An act the
handler may not perform or the case does not allow SHALL be shown and
disabled with the reason, and SHALL NOT be hidden. The menu SHALL ask the
server before it posts.

#### Scenario: a handler sees what they may do in one place
@e2e tests/e2e/lifecycle-acts-on-the-case.spec.ts

- **GIVEN** a case in an open status
- **WHEN** a handler opens the lifecycle menu
- **THEN** it SHALL list every act the provider publishes for that case

#### Scenario: a refused act says why
@e2e tests/e2e/lifecycle-acts-on-the-case.spec.ts

- **GIVEN** a case whose guard refuses closing
- **WHEN** a handler opens the menu
- **THEN** Close SHALL be shown and disabled
- **AND** it SHALL carry the guard's own sentence

#### Scenario: an act a handler may not perform is not simply missing

- **GIVEN** a handler without the archiving role
- **WHEN** they open the menu
- **THEN** Archive SHALL be shown and disabled
- **AND** it SHALL say which role is needed

### Requirement: Finish, abort, archive and reopen are four acts (REQ-LIFE-11)

Ending a case SHALL be four separate acts, each with its own permission,
its own recorded reason, and its own archival consequence. Finishing SHALL
require a result. Aborting SHALL record a result that is not a besluit.
Archiving SHALL write the retention rule from the result type. Reopening
SHALL keep the record that the case was ended, with who ended it and when.

#### Scenario: an intrekking is not a granted vergunning
@e2e tests/e2e/lifecycle-acts-on-the-case.spec.ts

- **GIVEN** an open aanvraag
- **WHEN** a handler aborts it as withdrawn
- **THEN** the case SHALL record an abort with its reason
- **AND** it SHALL NOT record a besluit

#### Scenario: archiving writes the retention rule
@e2e tests/e2e/lifecycle-acts-on-the-case.spec.ts

- **GIVEN** a finished case with a result type carrying an archival period
- **WHEN** a handler archives it
- **THEN** the retention rule SHALL be written from that result type

#### Scenario: reopening keeps the ending in the record
@e2e tests/e2e/lifecycle-acts-on-the-case.spec.ts

- **GIVEN** a case finished last month
- **WHEN** a handler reopens it
- **THEN** the case SHALL be open
- **AND** the record SHALL still name who finished it and when

#### Scenario: finishing without a result is refused

- **GIVEN** an open case with no result chosen
- **WHEN** a handler finishes it
- **THEN** it SHALL be refused
- **AND** the refusal SHALL name the missing result

### Requirement: A case closes before its phases are complete (REQ-LIFE-12)

A handler SHALL be able to close a case before its phases are complete,
recording the result and which phases were skipped. Every guard protecting
the result SHALL still run. An early close whose result cannot be named
SHALL be refused. A close with a preset outcome SHALL still record its
reason.

#### Scenario: a niet-ontvankelijkverklaring arrives in phase two
@e2e tests/e2e/lifecycle-acts-on-the-case.spec.ts

- **GIVEN** a case in phase two of five
- **WHEN** a handler closes it as niet-ontvankelijk
- **THEN** the case SHALL close with that result
- **AND** the skipped phases SHALL be recorded

#### Scenario: the guards still run

- **GIVEN** a case whose result guard refuses
- **WHEN** a handler closes it early
- **THEN** it SHALL be refused with the guard's sentence

#### Scenario: a preset outcome still records a reason
@e2e tests/e2e/lifecycle-acts-on-the-case.spec.ts

- **GIVEN** a quick close with a preset outcome
- **WHEN** a handler uses it
- **THEN** the case SHALL close with that outcome
- **AND** a reason SHALL be recorded

### Requirement: Incompleteness is recorded, not blocked and not hidden (REQ-LIFE-13)

A case SHALL be creatable with required fields knowingly left empty. The
case SHALL then record that it is incomplete and SHALL name which fields
are missing. It SHALL NOT report itself complete, and intake SHALL NOT be
refused. Acts that need the missing data SHALL be refused, naming the
field.

#### Scenario: a phone intake is not lost
@e2e tests/e2e/lifecycle-acts-on-the-case.spec.ts

- **GIVEN** a case type with three required fields
- **WHEN** a handler creates a case with one of them empty and confirms
- **THEN** the case SHALL be created
- **AND** it SHALL read incomplete, naming that field

#### Scenario: the incompleteness travels to the list
@e2e tests/e2e/lifecycle-acts-on-the-case.spec.ts

- **GIVEN** an incomplete case
- **WHEN** the working list is read
- **THEN** the case SHALL be marked incomplete

#### Scenario: an act that needs the field is refused

- **GIVEN** an incomplete case missing the applicant's address
- **WHEN** a handler sends the besluit
- **THEN** it SHALL be refused
- **AND** the refusal SHALL name the address

### Requirement: A draft case is private, unclocked and promoted once (REQ-LIFE-14)

A case SHALL be creatable as a draft. A draft SHALL bind no term, SHALL
appear in no working list, count or report, and SHALL be visible only to
its author. Promoting SHALL be one act that binds the term and makes the
case visible. The moment the draft was created SHALL be kept on the case.

#### Scenario: a concept-zaak is not already overdue
@e2e tests/e2e/lifecycle-acts-on-the-case.spec.ts

- **GIVEN** a case type with a statutory term
- **WHEN** a handler begins a draft
- **THEN** no term SHALL be bound
- **AND** the draft SHALL NOT be in the working list

#### Scenario: a draft is private to its author
@e2e tests/e2e/lifecycle-acts-on-the-case.spec.ts

- **GIVEN** a draft created by one handler
- **WHEN** another handler searches for it
- **THEN** it SHALL NOT be found

#### Scenario: promoting binds the clock
@e2e tests/e2e/lifecycle-acts-on-the-case.spec.ts

- **GIVEN** a draft case
- **WHEN** its author promotes it
- **THEN** the statutory term SHALL be bound
- **AND** the case SHALL carry the moment the draft was created

### Requirement: A case is held with a reason and a wake date (REQ-LIFE-15)

A handler SHALL be able to put a case on hold, recording a reason and a
date on which it returns. A held case SHALL be marked as held wherever it
is listed and SHALL return to the working queue on its date. A hold SHALL
NOT stop any statutory term.

#### Scenario: a case is parked until a date
@e2e tests/e2e/lifecycle-acts-on-the-case.spec.ts

- **GIVEN** an open case
- **WHEN** a handler holds it until 1 March with a reason
- **THEN** the case SHALL read held
- **AND** the reason and the date SHALL be recorded

#### Scenario: a hold does not stop the clock
@e2e tests/e2e/lifecycle-acts-on-the-case.spec.ts

- **GIVEN** a case with a running statutory term
- **WHEN** it is held
- **THEN** the term SHALL keep running

#### Scenario: it comes back on its date

- **GIVEN** a case held until yesterday
- **WHEN** the working queue is read
- **THEN** the case SHALL be in it
