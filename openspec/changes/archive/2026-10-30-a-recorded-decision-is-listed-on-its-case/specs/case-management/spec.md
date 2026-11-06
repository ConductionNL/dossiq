# case-management delta: a-recorded-decision-is-listed-on-its-case

## ADDED Requirements

### Requirement: A recorded decision is listed on its case (REQ-CM-81)

When a decision is recorded for a case from its document assessments, the case's `decisions` SHALL list it, once, alongside any decision listed before. Failing to update the case SHALL NOT undo the decision.

#### Scenario: The checklist ticks after the decision
@e2e exclude {asserted in tests/Unit/Service/WOODecisionServiceTest.php against an in-memory register; the checklist reads the field it fills}

- **GIVEN** a case in its decision stage with every document assessed
- **WHEN** the handler records the decision
- **THEN** the case's `decisions` SHALL contain the new decision
- **AND** the next-step item "Record the decision" SHALL read done
