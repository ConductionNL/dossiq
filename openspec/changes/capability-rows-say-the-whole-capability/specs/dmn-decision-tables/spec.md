# dmn-decision-tables

## ADDED Requirements

### Requirement: A decision table's answer is written to the case (REQ-CRS-16)

A flow step that evaluates a decision table SHALL write each output of the matching rule to the case field its mapping names, or start the action it names. A case for which no rule matches SHALL keep its fields unchanged.

Rows: `3.11` (dossiq matrix).

#### Scenario: The answer lands on a field
- **GIVEN** a decision table whose output risk class maps to the case field riskClass
- **WHEN** a case is evaluated and rule 2 matches with "high"
- **THEN** the case's riskClass reads high
