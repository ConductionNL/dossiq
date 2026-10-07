## ADDED Requirements

### Requirement: A split divides a case rather than duplicating it (REQ-CM-50)

Splitting a case SHALL open a second, separately numbered case and SHALL
move the documents, parties and tasks the handler chose. A moved item SHALL
leave a reference in the original case, so the original file still reads as
a whole. Splitting SHALL NOT copy the whole file to both cases.

#### Scenario: A document goes to one half and not the other
@e2e tests/e2e/splitting-a-case-and-its-incidents.spec.ts

- **GIVEN** a case with four documents and two parties
- **WHEN** a handler splits it, choosing two documents and one party for the new case
- **THEN** the new case SHALL hold those two documents and that party
- **AND** the original SHALL hold the other two documents and the other party
- **AND** the original SHALL show a reference to what moved

#### Scenario: A party relevant to both halves stays on both
@e2e exclude unit; CaseSplitServiceTest

- **GIVEN** a case with a party the handler marks as relevant to both halves
- **WHEN** the case is split
- **THEN** the party SHALL be present on both cases with its role intact

### Requirement: The split is related in both directions (REQ-CM-51)

A split SHALL write a typed relation on both cases, so each names the other
without a query. The relation SHALL be one of the types dossiq already
stores; a split SHALL NOT invent a private link.

#### Scenario: Both cases name each other
@e2e tests/e2e/splitting-a-case-and-its-incidents.spec.ts

- **GIVEN** a case split in two
- **WHEN** either case is opened
- **THEN** its Related tab SHALL name the other case and say the relation came from a split

### Requirement: A case type declares what a split may divide (REQ-CM-52)

A case type SHALL declare whether documents, parties and tasks may be
divided, defaulting to all three. A split of something the case type does
not allow SHALL be refused, and the refusal SHALL name the rule that refused
it.

#### Scenario: A case type that forbids dividing documents
@e2e tests/e2e/splitting-a-case-and-its-incidents.spec.ts

- **GIVEN** a case type declaring that documents may not be divided
- **WHEN** a handler tries to move a document to the new case
- **THEN** the attempt SHALL be refused
- **AND** the refusal SHALL name the case-type rule

### Requirement: A case holds several dated incidents (REQ-CM-53)

A case SHALL be able to hold several `caseIncident` records. Each SHALL carry
the date of the event, the moment it was recorded, the reporter, a
description, an owner, a state and an outcome. An incident SHALL NOT carry a
term or a decision of its own.

#### Scenario: Three reports on one address
@e2e tests/e2e/splitting-a-case-and-its-incidents.spec.ts

- **GIVEN** a case about one address
- **WHEN** three reports are recorded with dates in March, June and September
- **THEN** the case SHALL list three incidents in that order
- **AND** each SHALL show its own reporter and outcome

#### Scenario: A late recording shows its delay
@e2e exclude unit; IncidentServiceTest

- **GIVEN** an incident whose event date is three weeks before the day it was recorded
- **WHEN** the incidents are listed
- **THEN** it SHALL be ordered by the event date
- **AND** the recording delay SHALL be visible

### Requirement: An incident carries its own hand-off (REQ-CM-54)

An incident SHALL carry its own assignee, and reassigning it SHALL NOT
change who holds the case. Open incidents SHALL be countable and filterable
on the work list.

#### Scenario: One report moves to an inspector
@e2e tests/e2e/splitting-a-case-and-its-incidents.spec.ts

- **GIVEN** a case held by an area handler with two open incidents
- **WHEN** one incident is assigned to an inspector
- **THEN** that incident SHALL name the inspector
- **AND** the case SHALL still be held by the area handler

#### Scenario: The work list counts open incidents
@e2e exclude unit over the queue reader; IncidentQueueCountTest

- **GIVEN** five cases holding nine open incidents between them
- **WHEN** the work list is read with the open-incident filter
- **THEN** it SHALL return the five cases
- **AND** the open-incident count SHALL be nine
