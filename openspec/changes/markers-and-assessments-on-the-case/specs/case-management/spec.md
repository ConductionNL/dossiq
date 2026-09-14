## ADDED Requirements

### Requirement: An attention flag is raised and cleared with a written reason (REQ-MRK-01)

A case SHALL carry an attention flag that is raised with a written reason
and cleared with a written reason. Each act SHALL record who performed it
and when. Neither act SHALL be possible without a reason, and clearing SHALL
NOT delete the raising.

#### Scenario: Clearing without a reason is refused
@e2e tests/e2e/markers-and-assessments-on-the-case.spec.ts

- **GIVEN** a case flagged as needing attention
- **WHEN** a user clears the flag without writing a reason
- **THEN** the clearing SHALL be refused
- **AND** the flag SHALL still be raised

#### Scenario: Both acts are attributed and kept
@e2e tests/e2e/markers-and-assessments-on-the-case.spec.ts

- **GIVEN** a case flagged by one handler and cleared by another, both with reasons
- **WHEN** the case is read
- **THEN** both acts SHALL be readable with their reasons, names and moments

#### Scenario: A case flagged four times shows four raisings
@e2e exclude unit; CaseAttentionFlagTest

- **GIVEN** a case raised and cleared four times over a year
- **WHEN** its flag history is read
- **THEN** four raisings and four clearings SHALL be returned
- **AND** no earlier reason SHALL have been overwritten

#### Scenario: The work list filters on the flag
@e2e tests/e2e/markers-and-assessments-on-the-case.spec.ts

- **GIVEN** three flagged cases among twenty
- **WHEN** the work list is filtered on needing attention
- **THEN** exactly the three SHALL be listed

### Requirement: The case carries an assessed risk level behind its own permission (REQ-MRK-02)

A case SHALL be able to carry a `riskAssessment` with a level, the ground it
rests on, the assessor, the assessment date and a review date. Reading it
SHALL require a permission beyond the permission to read the case, declared
in the platform's field-level vocabulary rather than checked in dossiq code.
The assessment SHALL be available to every domain, not to VTH alone.

#### Scenario: Reading the case does not reveal the level
@e2e tests/e2e/markers-and-assessments-on-the-case.spec.ts

- **GIVEN** a case with a risk assessment
- **WHEN** a handler without the extra permission opens it
- **THEN** the case SHALL be readable
- **AND** the level, the ground and the assessor SHALL NOT be returned

#### Scenario: The permitted reader sees the assessment and its ground
@e2e tests/e2e/markers-and-assessments-on-the-case.spec.ts

- **GIVEN** the same case
- **WHEN** a handler with the extra permission opens it
- **THEN** the level, the ground, the assessor and the dates SHALL be readable

#### Scenario: A stale assessment says so
@e2e exclude unit; CaseRiskAssessmentTest

- **GIVEN** an assessment whose review date has passed
- **WHEN** it is read
- **THEN** it SHALL be marked as due for review

#### Scenario: The level feeds impact, not a new priority word
@e2e exclude unit; RiskFeedsImpactTest

- **GIVEN** a case type whose matrix reads impact from the risk assessment
- **WHEN** the assessed level rises
- **THEN** the derived priority SHALL be recomputed through REQ-PRI-02
- **AND** no priority value outside `low`, `normal`, `high`, `urgent` SHALL be written

### Requirement: The system raises attention markers against a named tab (REQ-MRK-03)

A marker SHALL declare the tab it points at, the condition that raises it
and the condition that clears it. A marker SHALL be raised by the system,
SHALL NOT be per user, and SHALL NOT clear because somebody opened the tab.
It SHALL clear when the condition behind it stops being true.

#### Scenario: A failed scan marks the documents tab
@e2e tests/e2e/markers-and-assessments-on-the-case.spec.ts

- **GIVEN** a case whose document failed a virus scan
- **WHEN** the case is opened
- **THEN** the Documents tab SHALL carry an attention marker naming the reason

#### Scenario: Opening the tab does not clear the marker
@e2e tests/e2e/markers-and-assessments-on-the-case.spec.ts

- **GIVEN** the same case
- **WHEN** the handler opens the Documents tab and leaves again
- **THEN** the marker SHALL still be there
- **AND** the per-user unread badge SHALL have cleared, being a different fact

#### Scenario: Handling the work clears the marker
@e2e tests/e2e/markers-and-assessments-on-the-case.spec.ts

- **GIVEN** the same case
- **WHEN** the failed document is removed or replaced
- **THEN** the marker SHALL clear on its own
- **AND** no person SHALL have had to dismiss it

#### Scenario: A marker without a clearing condition is refused
@e2e exclude structural, over the shipped declarations; CaseAttentionMarkerTest

- **GIVEN** a marker declared with a raise condition and no clear condition
- **WHEN** the declarations are validated
- **THEN** the validation SHALL fail naming the marker
