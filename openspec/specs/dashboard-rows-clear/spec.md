# dashboard-rows-clear Specification

## Purpose
What dossiq does for dashboard rows clear. The requirements were first written by the change `dashboard-rows-clear` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-dashboard-rows-clear/`) and cover: the bottom rows of My work and My team start under every card above.

## Requirements

### Requirement: the bottom rows of My work and My team start under every card above

The My work view's followed cases and archival reviews, and the My team view's cases per status and per type, MUST be placed with `clear: true`.

#### Scenario: My work
- GIVEN Recent activity reaching lower than Verder werken
- THEN Zaken die u volgt starts under Recent activity, level with Mijn archiefbeoordelingen
