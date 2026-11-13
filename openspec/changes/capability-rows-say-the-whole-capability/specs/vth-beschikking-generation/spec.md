# vth-beschikking-generation

## ADDED Requirements

### Requirement: A decision letter can be generated as a step of the case's flow (REQ-CRS-06)

A case type's flow SHALL be able to carry a step that generates the decision letter from its template. When the case reaches that step, the letter SHALL be generated and filed on the case without a handler opening the composer.

Rows: `7.4` (dossiq matrix).

#### Scenario: The flow reaches the decision step
- **GIVEN** a case type whose flow generates the decision letter when the case enters Decision
- **WHEN** a case enters Decision
- **THEN** the decision letter is filed on the case
