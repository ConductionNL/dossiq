# status-transition-engine

## ADDED Requirements

### Requirement: An administrator writes a rule's conditions and actions (REQ-CRS-38)

The Rules tab of a case type SHALL let an administrator add a rule and change one, naming its conditions and the action it takes. A saved rule SHALL be listed and tried like every other rule.

Rows: `11.20` (dossiq matrix).

#### Scenario: A new rule
- **GIVEN** an administrator on a case type's Rules tab
- **WHEN** they add a rule that requires a document when the status is Decision
- **THEN** the rule is listed and trying it on a case shows its verdict
