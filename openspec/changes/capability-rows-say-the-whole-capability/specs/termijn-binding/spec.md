# termijn-binding

## ADDED Requirements

### Requirement: A lead time in days yields the case's target date (REQ-CRS-19)

A case type whose term is a lead time in days SHALL give each new case a target date that many days after its start date. The case page SHALL show that date.

Rows: `8.1` (dossiq matrix).

#### Scenario: Eight weeks from the start
- **GIVEN** a case type with a lead time of 56 days
- **WHEN** a case starts on 1 March
- **THEN** its target date reads 26 April
