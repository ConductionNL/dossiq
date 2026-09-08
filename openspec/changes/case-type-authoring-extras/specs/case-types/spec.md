## ADDED Requirements

### Requirement: You give each status a colour and a list visibility (REQ-CT-19)

You give each status a colour and choose whether it shows in lists. The
`statusType` schema SHALL carry `colour`, one of the NL Design System hue
names, and `hiddenInLists`, a boolean. The status badge on the case and the
Workflow board column SHALL render in that colour. The Cases index SHALL
leave cases in a hidden status out unless you ask for closed cases.

**Feature tier**: MVP

#### Scenario: A coloured status shows on the board
@e2e tests/e2e/case-type-authoring-extras.spec.ts

- **GIVEN** the status In behandeling of a type has the colour orange
- **WHEN** you open the Workflow board for that type
- **THEN** the In behandeling column header SHALL render in the orange token

#### Scenario: A hidden status keeps its cases out of the list
@e2e tests/e2e/case-type-authoring-extras.spec.ts

- **GIVEN** the status Afgehandeld is marked hidden in lists
- **AND** three cases sit in Afgehandeld
- **WHEN** you open the Cases index
- **THEN** none of the three SHALL be listed
- **AND** the Closed chip SHALL list them

### Requirement: You derive a case type from a parent (REQ-CT-20)

You derive a case type from a parent and change only what differs. The
`caseType` schema SHALL carry `parentCaseType`, a reference to another case
type. A child SHALL inherit the parent's statuses, results, properties and
deadlines, and a row the child declares with the same name SHALL replace the
parent's. A chain that returns to itself SHALL be refused on save.

**Feature tier**: MVP

#### Scenario: A child shows its parent's statuses
@e2e tests/e2e/case-type-authoring-extras.spec.ts

- **GIVEN** the type Bezwaar has four statuses
- **AND** the type Bezwaar (verkort) names Bezwaar as its parent and declares none
- **WHEN** you open Bezwaar (verkort)
- **THEN** its Statuses tab SHALL list the four statuses marked Inherited

#### Scenario: A child overrides one deadline
@e2e tests/e2e/case-type-authoring-extras.spec.ts

- **GIVEN** Bezwaar has a processing deadline of 12 weeks
- **AND** Bezwaar (verkort) sets its own deadline to 6 weeks
- **WHEN** you file a case of Bezwaar (verkort)
- **THEN** the case's deadline SHALL be 6 weeks after its start date

#### Scenario: A cycle is refused
@e2e tests/e2e/case-type-authoring-extras.spec.ts

- **GIVEN** Bezwaar (verkort) names Bezwaar as its parent
- **WHEN** you set Bezwaar's parent to Bezwaar (verkort) and save
- **THEN** the save SHALL fail with a message naming the cycle
