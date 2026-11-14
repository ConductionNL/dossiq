## ADDED Requirements

### Requirement: A deelzaak inherits its parent's grants (REQ-DZ-20)

The case schema SHALL declare `parentCase` as its hierarchy edge, so a
grant on a case SHALL reach its deelzaken and their deelzaken without a
second grant. `relatedCases` SHALL NOT be declared as a hierarchy edge.
The inherited grant SHALL carry the parent's verbs and no others.
`CaseAccessGuard` SHALL ask the platform for read and mutation access and
SHALL NOT keep a resolution of its own.

#### Scenario: access to a case reaches its deelzaak
@e2e tests/e2e/deelzaken-inherit-the-parent-grants.spec.ts

- **GIVEN** a case with a deelzaak and a deelzaak of that deelzaak
- **AND** a colleague granted read on the case only
- **WHEN** the colleague opens the deepest deelzaak
- **THEN** it SHALL be readable

#### Scenario: read does not become write
@e2e tests/e2e/deelzaken-inherit-the-parent-grants.spec.ts

- **GIVEN** the same colleague with read on the parent
- **WHEN** they try to change a field on the deelzaak
- **THEN** the write SHALL be refused

#### Scenario: removing the grant on the parent removes it below
@e2e tests/e2e/deelzaken-inherit-the-parent-grants.spec.ts

- **GIVEN** the same colleague reading the deelzaak through the parent
- **WHEN** the grant on the parent is removed
- **THEN** the deelzaak SHALL no longer be readable by them

#### Scenario: a related case is not a parent

- **GIVEN** two cases linked through `relatedCases` and a grant on one
- **WHEN** the holder opens the other
- **THEN** access SHALL be refused

### Requirement: The case says where access came from (REQ-DZ-21)

A case opened through a grant inherited from an ancestor SHALL name the
case that granted it. The Sharing tab of a case that has deelzaken SHALL
state that a share reaches them, before the share is made.

#### Scenario: the handler can see why a colleague is there
@e2e tests/e2e/deelzaken-inherit-the-parent-grants.spec.ts

- **GIVEN** a deelzaak a colleague reads through a grant on its parent
- **WHEN** a handler opens the deelzaak's access information
- **THEN** the colleague SHALL be listed with the parent case named as the source

#### Scenario: sharing a parent warns first
@e2e tests/e2e/deelzaken-inherit-the-parent-grants.spec.ts

- **GIVEN** a case with two deelzaken
- **WHEN** a handler opens the Sharing tab
- **THEN** it SHALL state that a share reaches the deelzaken
