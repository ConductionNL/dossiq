## ADDED Requirements

### Requirement: A case plan holds interventions with a goal, a provider and dates (REQ-CPN-01)

A case plan SHALL hold interventions. Each intervention SHALL name what it
is, the goal it serves, its provider, its start date, its target date, its
state and its outcome. Several interventions SHALL be able to serve one
goal. A goal SHALL name what would count as met. A plain string SHALL NOT
stand in for a goal or an intervention.

#### Scenario: A plan with two goals and three interventions
@e2e tests/e2e/the-social-domain-plan-and-its-grounds.spec.ts

- **GIVEN** a household with a case plan
- **WHEN** two goals are set and three interventions are planned against them
- **THEN** each intervention SHALL name its goal, its provider and its target date
- **AND** one goal SHALL be able to carry two of the interventions

#### Scenario: An overdue intervention is visible as overdue
@e2e tests/e2e/the-social-domain-plan-and-its-grounds.spec.ts

- **GIVEN** an intervention whose target date has passed and which is not complete
- **WHEN** the plan is opened
- **THEN** the intervention SHALL be shown as overdue

#### Scenario: The provider is a party, not a name typed twice
@e2e exclude unit; InterventionProviderTest

- **GIVEN** an intervention with a provider
- **WHEN** the provider is read
- **THEN** it SHALL be a party in the platform's contact model with its contact details

#### Scenario: A goal can be evaluated
@e2e exclude unit; CasePlanGoalTest

- **GIVEN** a goal naming what would count as met
- **WHEN** a review records that it was met
- **THEN** the goal SHALL be closed with that observation
- **AND** a goal naming nothing measurable SHALL be refused on save

### Requirement: The existing plan text is migrated, not discarded (REQ-CPN-02)

Every string in `gezinsplan.goals` and `deploymentTrajectories` SHALL be
migrated into a goal or an intervention with its text intact, its unmapped
fields empty and its origin marked. No string SHALL be dropped and no value
SHALL be invented for a field the old shape did not carry.

#### Scenario: An old goal survives the migration
@e2e exclude unit; CasePlanStringMigrationTest

- **GIVEN** a gezinsplan with four goal strings and two trajectory strings
- **WHEN** the migration runs
- **THEN** six records SHALL exist carrying those exact texts
- **AND** each SHALL be marked as migrated from the old shape
- **AND** no provider or target date SHALL have been invented

### Requirement: A plan is reviewed, and the review is recorded (REQ-CPN-03)

A case plan SHALL carry a review date. A review SHALL record who reviewed it
and what changed. A plan whose review date has passed SHALL be visible as
due for review.

#### Scenario: A stale plan says it is stale
@e2e tests/e2e/the-social-domain-plan-and-its-grounds.spec.ts

- **GIVEN** a plan whose review date passed last month
- **WHEN** the case is opened
- **THEN** the plan SHALL be shown as due for review

#### Scenario: A review records what changed
@e2e exclude unit; CasePlanReviewTest

- **GIVEN** a plan under review
- **WHEN** one goal is closed and one intervention is added
- **THEN** the review SHALL record both changes, the reviewer and the date
