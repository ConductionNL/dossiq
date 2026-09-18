## ADDED Requirements

### Requirement: A case type may name a mother (REQ-CTI-01)

`caseType` SHALL carry an optional `parentCaseType` referencing another case
type. A case type with a `parentCaseType` is a child. A case type without one
SHALL behave exactly as before this change, so no existing case type is
affected by the feature existing.

#### Scenario: A case type without a mother is unchanged
@e2e exclude Register declaration, covered by vitest.

- **GIVEN** a seeded case type with no `parentCaseType`
- **WHEN** it is published
- **THEN** its properties, statuses and roles SHALL be exactly what it declares

### Requirement: A child compiles its mother at publish (REQ-CTI-02)

Publishing a child SHALL compile the mother's properties, status types,
transitions, role types, document types and result types into the child, then
apply the child's own additions and overrides by key. The compile SHALL run
on publish and SHALL NOT run on read, so a case that is already running never
changes underneath its handler.

#### Scenario: A child gains the mother's statuses
@e2e tests/e2e/case-type-inheritance.spec.ts

- **GIVEN** a mother with three status types and a child declaring one more
- **WHEN** the child is published
- **THEN** the child SHALL carry four status types
- **AND** the three from the mother SHALL carry `inheritedFrom` naming her

#### Scenario: A child overrides by key
@e2e exclude Compiler behaviour, covered by PHPUnit.

- **GIVEN** a mother declaring a property `doorlooptijd` of 8 weeks and a
  child declaring `doorlooptijd` of 12 weeks
- **WHEN** the child is published
- **THEN** the child's `doorlooptijd` SHALL be 12 weeks
- **AND** it SHALL NOT carry `inheritedFrom`

#### Scenario: A running case is not changed by a mother edit
@e2e tests/e2e/case-type-inheritance.spec.ts

- **GIVEN** a case running on a published child
- **WHEN** the mother is edited and published
- **THEN** the running case SHALL keep the case type version it started on

### Requirement: A mother offers her children a new version (REQ-CTI-03)

Publishing a mother SHALL list her children and SHALL offer a new draft
version per child, created through the existing version chain. A child SHALL
NOT be republished without that press, so nobody has their case type changed
without being asked.

#### Scenario: One child is republished and its sibling is not
@e2e tests/e2e/case-type-inheritance.spec.ts

- **GIVEN** a mother with two published children
- **WHEN** the mother is published and only the first child is republished
- **THEN** the first child SHALL carry the mother's new element
- **AND** the second child SHALL still carry the previous compilation

### Requirement: A child cannot break its mother's contract (REQ-CTI-04)

Publish validation SHALL refuse a child that removes an element the mother
declares required, naming the element. It SHALL refuse a `parentCaseType`
that would form a cycle, naming both case types. Neither refusal SHALL leave
a partly compiled child behind.

#### Scenario: Removing a required status is refused
@e2e exclude Publish validation, covered by PHPUnit.

- **GIVEN** a mother declaring a status type as required and a child that
  removes it
- **WHEN** the child is published
- **THEN** publication SHALL be refused naming that status type

#### Scenario: A cycle is refused
@e2e exclude Publish validation, covered by PHPUnit.

- **GIVEN** a case type A whose mother is B, and B set to have A as its mother
- **WHEN** B is published
- **THEN** publication SHALL be refused naming A and B

### Requirement: The page says what came from the mother (REQ-CTI-05)

`#CaseTypeDetail` SHALL show a Mother panel naming the `parentCaseType` and
linking to it, and SHALL mark every compiled element with the mother it came
from. An element the child overrode SHALL read as this case type's own.

#### Scenario: An inherited status is marked
@e2e tests/e2e/case-type-inheritance.spec.ts

- **GIVEN** a published child
- **WHEN** an admin opens its page
- **THEN** the Mother panel SHALL name the parent case type
- **AND** each inherited status SHALL be marked with her name
