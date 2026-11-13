# dashboard

## ADDED Requirements

### Requirement: A dashboard can show a KPI as a gauge (REQ-CRS-35)

A reader arranging a dashboard SHALL be able to add a KPI as a gauge that shows the value against its target. The gauge SHALL show the value and the target in words as well.

Rows: `10.1` (dossiq matrix).

#### Scenario: A gauge against its target
- **GIVEN** a KPI of 80 percent on time against a target of 90
- **WHEN** a reader adds it as a gauge
- **THEN** the gauge shows 80 against 90 and reads "80% of target 90%"
