## ADDED Requirements

### Requirement: Cases and tasks carry a team assignment

You assign a case or a task to a team, not only to a person. Schema `case`
SHALL gain the property `assignedGroup` and schema `caseTask` the property
`assigneeGroup`, both a `$ref` to `organisatieRol`, titled Team, facetable.
The Cases and Tasks indexes SHALL show a Team column and a Team facet in the
sidebar, and the edit forms SHALL offer the team as a picker over
`organisatieRol`. Assigning a team SHALL NOT clear the personal assignee.

#### Scenario: Assign a case to a team
@e2e tests/e2e/case-parties.spec.ts

- **GIVEN** an organisation role Team Permits exists
- **WHEN** you edit a case and pick Team Permits as its team
- **THEN** the Cases index SHALL show Team Permits in the Team column for that case
- **AND** the Team facet SHALL list Team Permits with a count of one

#### Scenario: Assign a task to a team
@e2e tests/e2e/case-parties.spec.ts

- **GIVEN** an open task on a case
- **WHEN** you set its team to Team Permits
- **THEN** the Tasks index SHALL show the team on that row and the assignee SHALL stay as it was

### Requirement: Mine is a quick filter on both indexes

You switch to your own work with one click. The `Cases` and `Tasks` indexes
SHALL carry a `quickFilters` chip Mine with the filter `assignee = @me`. A
Team chip waits for the platform to resolve the signed-in handler's teams;
until then the Team facet is the way to narrow the list to a team.

#### Scenario: Mine shows only my cases
@e2e tests/e2e/case-parties.spec.ts

- **GIVEN** cases assigned to you and to a colleague
- **WHEN** you press the Mine chip on Cases
- **THEN** only the cases assigned to you SHALL remain in the list
