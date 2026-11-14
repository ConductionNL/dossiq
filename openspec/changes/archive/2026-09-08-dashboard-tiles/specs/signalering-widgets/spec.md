# signalering-widgets (delta)

## ADDED Requirements

### Requirement: One deadlines table replaces the overdue and deadline alert tiles (REQ-SIG-004)
The Dashboard SHALL show open cases whose deadline is past or within three days in one table ordered by deadline, with days left on every row. A row past its deadline SHALL read in the error text colour. You see each case once.

#### Scenario: Overdue and near-deadline cases share one table
- **GIVEN** a case two days past its deadline and a case due in two days
- **WHEN** you open the Dashboard
- **THEN** the Deadlines table lists both, the overdue one first and in red, and no second tile repeats either row
- @e2e covered by `tests/e2e/dashboard-tiles.spec.ts` (tasks.md 3.1)

#### Scenario: Closed cases stay out
- **GIVEN** a case in a final status whose deadline is past
- **WHEN** you open the Dashboard
- **THEN** the Deadlines table does not list it
- @e2e covered by `tests/e2e/dashboard-tiles.spec.ts` (tasks.md 3.1)
