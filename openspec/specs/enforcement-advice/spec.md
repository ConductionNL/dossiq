# enforcement-advice Specification

## Purpose
What dossiq does for enforcement advice. The requirements were first written by the change `screens-round12-enforcement-advice` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-screens-round12-enforcement-advice/`) and cover: An enforcement advice reads as its board (REQ-EA-1).

## Requirements

### Requirement: An enforcement advice reads as its board (REQ-EA-1)

`LhsRecommendationDetail` SHALL show the final intervention as the header
pill, Enforcement as the breadcrumb, the calculation date in the meta line,
and a side column with the case and inspection, the behaviour, consequence and
matrix version, and the history.

#### Scenario: The advised intervention heads the page
@e2e exclude measured against board DqHandhaving by the round 12 pixel check, not an e2e flow

- **GIVEN** an advice whose final intervention is an order subject to a penalty
- **WHEN** you open it
- **THEN** the header pill SHALL read Order subject to a penalty and the side column SHALL name its case
