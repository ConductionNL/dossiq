# signalering-widgets Delta: lists-and-dashboards-as-drawn

## ADDED Requirements

### Requirement: The dossiq dashboard draws the five signal tiles of DqDashboard

The Dashboard SHALL draw, under the case tiles, five signal tiles as library record lists: "Mijn open werk" (the reader's open cases and open tasks, soonest first), "Wachtrij van uw teams" (unclaimed open cases of the reader's own teams, each with Oppakken), "Termijnsignalen" (late cases, then cases in the last quarter of their term), "Taakherinneringen" (the reader's late tasks, then those due within three days) and "Woo-termijnen" (every running Woo request by days to its decision date). The Woo case type SHALL be named by configuration, not in code. A reader in no team SHALL get no team queue rows.

#### Scenario: A handler opens the dashboard
@e2e exclude manifest configuration over library widgets; covered by tests/vitest/dashboardSignalTiles.spec.js
- **GIVEN** a handler with open cases, open tasks and a team with unclaimed cases
- **WHEN** they open the Dashboard
- **THEN** they see the five tiles with the board's titles
- **AND** each unclaimed case of their team offers Oppakken
