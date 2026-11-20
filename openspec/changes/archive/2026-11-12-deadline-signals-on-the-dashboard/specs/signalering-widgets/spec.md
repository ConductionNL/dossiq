# signalering-widgets Delta: deadline-signals-on-the-dashboard

## MODIFIED Requirements

### Requirement: One deadlines table replaces the overdue and deadline alert tiles (REQ-SIG-004)
The Dashboard SHALL show, in one table titled "Termijnsignalen" (English source "Deadline signals") under the line "Te laat, en minder dan een kwart van de termijn over", the open cases that are past their deadline or have less than a quarter of their term left, as board DqDashboard draws it. The term runs from the case's start date to its deadline; a case without a start date signals only once it is late. The table SHALL be ordered by deadline, so the latest case comes first, and show the days left on every row. A row past its deadline SHALL read in the error text colour. You see each case once. With nothing to show, the table SHALL read "Geen termijnsignalen", and its footer link SHALL read "Alle termijnen" and open the cases list on the same filter.

#### Scenario: Overdue and near-deadline cases share one table
- **GIVEN** a case two days past its deadline and a case with two days of a seven-day term left
- **WHEN** you open the Dashboard
- **THEN** the Termijnsignalen table lists both, the overdue one first and in red, and no second tile repeats either row
- @e2e covered by `tests/e2e/dashboard-tiles.spec.ts`

#### Scenario: A long term with days to spare stays out
- **GIVEN** an open case with five days of a 56-day term left
- **WHEN** you open the Dashboard
- **THEN** the Termijnsignalen table does not list it
- @e2e exclude the window is one server-side filter on the materialised `termSignalFrom`, held by `tests/vitest/dashboardWorkTables.spec.js`; the e2e seeds one case type with a seven-day term

#### Scenario: Closed cases stay out
- **GIVEN** a case in a final status whose deadline is past
- **WHEN** you open the Dashboard
- **THEN** the Termijnsignalen table does not list it
- @e2e covered by `tests/e2e/dashboard-tiles.spec.ts`
