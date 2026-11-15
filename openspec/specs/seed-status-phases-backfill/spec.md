# seed-status-phases-backfill Specification

## Purpose
What dossiq does for seed status phases backfill. The requirements were first written by the change `seed-status-phases-backfill` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-seed-status-phases-backfill/`) and cover: A seeded status without a phase gets the seed's phase.

## Requirements

### Requirement: A seeded status without a phase gets the seed's phase

On every repair run, the case-flow seed step SHALL set `phase` on a stored status of a seeded case type whose phase is
empty, from the status of the same name in `case_flow_seed_data.json`. It SHALL NOT change a phase that is set, nor a
status that belongs to another case type.

#### Scenario: A status seeded before phases

- **GIVEN** "Bij commissie" stored without a phase on "Omgevingsvergunning kleine bouwactiviteit"
- **WHEN** the repair step runs
- **THEN** its phase is Advies
