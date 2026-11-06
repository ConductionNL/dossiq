# phase-days-from-completed-visits Specification

## Purpose
What dossiq does for phase days from completed visits. The requirements were first written by the change `phase-days-from-completed-visits` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-phase-days-from-completed-visits/`) and cover: Days per phase counts completed visits.

## Requirements

### Requirement: Days per phase counts completed visits

The process-mining days-per-phase matrix SHALL be computed from dwell visits that ended (by a next status or the case's
close), not from the visit an open case is still in.

#### Scenario: An open case in its second status

- **GIVEN** an open case that was in Ontvangst for two days and is now in Beoordeling
- **THEN** the matrix has an Ontvangst column for it and no Beoordeling column
