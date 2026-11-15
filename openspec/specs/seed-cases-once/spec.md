# seed-cases-once Specification

## Purpose
What dossiq does for seed cases once. The requirements were first written by the change `seed-cases-once` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-seed-cases-once/`) and cover: The demo cases are seeded once.

## Requirements

### Requirement: The demo cases are seeded once

The case-flow seed repair step SHALL create its demo cases only when `demo_data_decided` is `installed` and the seed
version is not yet recorded in `case_flow_seed_cases_version`, and SHALL record the version after a run that completed
for every case type. It SHALL keep completing the seed's case types and statuses on every run.

#### Scenario: A deleted demo case

- **GIVEN** an instance that opted into demo data and ran the seed once
- **WHEN** an operator deletes a demo case and the repair step runs again
- **THEN** the case is not created again

#### Scenario: No demo data

- **GIVEN** `demo_data_decided` is `skipped`
- **THEN** the case type and its statuses are seeded and no demo case is
