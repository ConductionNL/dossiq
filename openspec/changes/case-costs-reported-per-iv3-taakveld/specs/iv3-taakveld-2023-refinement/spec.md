## ADDED Requirements

### Requirement: The reports page shows case costs per Iv3 taakveld per quarter (REQ-IV3-REPORT-01)

The reports page SHALL show, for a chosen quarter, the summed case costs per Iv3 taakveld, with every 2023 refinement code rolled up to its pre-2023 parent through `Iv3TaakveldList::aggregationKeyFor()`, and SHALL export the same table as CSV. Cases without a taakveld SHALL be one row "Geen taakveld". The aggregation SHALL be a generic grouped-cost report configured with the taakveld field and the key map.

#### Scenario: Old and new codes land in one bucket

- **GIVEN** in Q1 one case on taakveld 6.71 costing 1.000 euro and one on its 2023 refinement 6.71a costing 500 euro
- **WHEN** the controller opens the Iv3 report for Q1
- **THEN** one row 6.71 SHALL show 1.500 euro
- @e2e tests/e2e/iv3-report.spec.ts

#### Scenario: The CSV matches the table

- **GIVEN** the Iv3 report for Q1 on screen
- **WHEN** the controller exports it
- **THEN** the CSV SHALL hold the same rows and totals
- @e2e tests/e2e/iv3-report.spec.ts
