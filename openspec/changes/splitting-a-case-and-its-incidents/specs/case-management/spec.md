## ADDED Requirements

### Requirement: A case splits by moving what was chosen (REQ-SPL-01)

Splitting a case SHALL move the documents and parties the handler chose onto a
second case. The moved rows SHALL leave the first case: splitting SHALL NOT copy
the whole file to both, which is what `CaseCopyService` already does and what
this row is rated `partial` for. The typed relation written on both cases is the
reference that keeps the original file readable as a whole.

The second case SHALL be opened by the caller and not minted here. A split that
also created a case would duplicate `CaseCopyService`, and the two would drift
on what a new case gets.

TASKS ARE NOT DIVIDED. The change's own tasks name three kinds. dossiq has had
no task table since `remove-casetask`: a task is the workflow engine's record,
and moving one is the engine's act, not a repointed row here. `tasks` is
therefore absent from the divisible set and a request naming it is refused,
rather than accepted and silently ignored.

A row that is not on the case being split SHALL be refused and not moved. Without
that check a handler could move another case's document onto theirs by passing
its id, and the audit trail would record dossiq doing it for them.

A split SHALL report what moved and what did not. A split that moved four of six
documents and answered success leaves a handler believing a division that did not
happen.

#### Scenario: A document goes to one half and not the other
@e2e tests/e2e/splitting-a-case-and-its-incidents.spec.ts

- **GIVEN** a case holding a document
- **WHEN** a handler splits it, choosing that document for the second case
- **THEN** the second case SHALL hold the document
- **AND** the first case SHALL NOT hold it any more
- **AND** the two cases SHALL name each other

#### Scenario: A party relevant to both halves stays on both
@e2e exclude a party kept on both halves is a party the handler did not choose, so the mechanism is "it was not moved"; `tests/Unit/Service/CaseSplitServiceTest.php` drives the move and the refusal, and nothing moves a row that was not named

- **GIVEN** a case with a party the handler does not choose
- **WHEN** the case is split
- **THEN** the party SHALL stay on the first case with its role intact

#### Scenario: A row on another case is refused
@e2e exclude unit; `tests/Unit/Service/CaseSplitServiceTest.php::testARowOnAnotherCaseIsRefused`

- **GIVEN** a document belonging to a case that is not being split
- **WHEN** its id is passed to the split
- **THEN** it SHALL be reported refused and SHALL NOT be moved

### Requirement: The split is related in both directions (REQ-SPL-02)

A split SHALL write a typed relation on both cases, so each names the other
without a query. The relation SHALL be one of the types dossiq already
stores; a split SHALL NOT invent a private link.

#### Scenario: Both cases name each other
@e2e tests/e2e/splitting-a-case-and-its-incidents.spec.ts

- **GIVEN** a case split in two
- **WHEN** either case is opened
- **THEN** each case SHALL name the other, in both directions

Both directions are written by dossiq today. OpenRegister's
`relation-types-with-inverses` will own the inverse; until it lands, a relation
written one way only leaves a case reachable from its sibling and not back.

### Requirement: A case type declares what a split may divide (REQ-SPL-03)

A case type SHALL declare whether documents and parties may be divided. An
absent or empty declaration SHALL mean everything this app can divide, which is
how every case type behaves today: shipping this change must not narrow a case
type nobody has administered yet.

A split of something the case type does not allow SHALL be refused with a status
and a message naming what was refused. A handler told "not allowed" goes looking
for a permission; one told which declaration refused them goes to the case type.

An unreadable case type SHALL allow what it always allowed. Refusing every split
because a read failed would take a gesture away over an outage.

#### Scenario: A case type that forbids dividing documents
@e2e tests/e2e/splitting-a-case-and-its-incidents.spec.ts

- **GIVEN** a case type declaring that documents may not be divided
- **WHEN** a handler tries to move a document to the new case
- **THEN** the attempt SHALL be refused
- **AND** the refusal SHALL name the case-type rule

### Requirement: A case holds dated incidents with their own owners (REQ-INC-01)

A case SHALL be able to hold several `incident` records. Each SHALL carry the
date of the event, the moment it was recorded, the reporter, a description, an
owner, a state and an outcome. An incident SHALL NOT carry a term, a number or a
decision of its own: making it a deelzaak would give every report a
beslistermijn it does not have.

Incidents SHALL be listed by the date of the EVENT and not by when they were
recorded. A report typed up three weeks late belongs where it happened, and a
list ordered by creation hides the pattern the case is kept open for.

`recordedAt` SHALL be stamped when the incident is written and SHALL NOT be
taken from the caller, because a caller-supplied value erases the delay that is
the point of storing both.

`state` SHALL be a plain field. Which transition is allowed from which state is
the workflow engine's question, and dossiq declares no second state machine. An
incident with no state recorded SHALL count as open: a report nobody has
classified still needs somebody.

#### Scenario: Three reports on one address
@e2e tests/e2e/splitting-a-case-and-its-incidents.spec.ts

- **GIVEN** a case about one address
- **WHEN** three reports are recorded with dates in March, June and September
- **THEN** the case SHALL list three incidents in that order
- **AND** each SHALL show its own reporter and outcome

#### Scenario: A late recording shows its delay
@e2e exclude unit; `tests/Unit/Service/IncidentServiceTest.php::testALateRecordingShowsItsDelay`, which also pins the delay as CALENDAR days rather than elapsed ones: from 11 March to 1 April the clocks go forward, so the elapsed time is 20 days 23 hours and a naive count reads one short for half the year

- **GIVEN** an incident whose event date is three weeks before the day it was recorded
- **WHEN** the incidents are listed
- **THEN** it SHALL be ordered by the event date
- **AND** the recording delay SHALL be visible

### Requirement: An incident hands off without moving the case (REQ-INC-02)

An incident SHALL carry its own assignee, and reassigning it SHALL NOT change
who holds the case. A hand-off that moved the case would take the other reports
with it, which is what makes people open three cases instead of one.

Open incidents SHALL be countable on a case. Counting them on the WORK LIST is
the queue reader's, and it is named in this change's PR body rather than built
here: the list reads cases, and an incident is not one.

#### Scenario: One report moves to an inspector
@e2e tests/e2e/splitting-a-case-and-its-incidents.spec.ts

- **GIVEN** a case held by an area handler with two open incidents
- **WHEN** one incident is assigned to an inspector
- **THEN** that incident SHALL name the inspector
- **AND** the case SHALL still be held by the area handler

#### Scenario: The work list counts open incidents
@e2e exclude NOT BUILT HERE. The count on one case is `IncidentService::openCountOn`, asserted in `tests/Unit/Service/IncidentServiceTest.php`; surfacing it on the work list is the queue reader's and is named in the PR body

- **GIVEN** five cases holding nine open incidents between them
- **WHEN** the work list is read with the open-incident filter
- **THEN** it SHALL return the five cases
- **AND** the open-incident count SHALL be nine
