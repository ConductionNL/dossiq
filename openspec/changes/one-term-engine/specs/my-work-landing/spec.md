## MODIFIED Requirements

### Requirement: My Work carries three widgets, the Dashboard carries none of them

The three widgets below SHALL render on My Work and SHALL NOT render on the
Dashboard. The Dashboard's KPI cards, status/type charts, and Stalled Cases
panel are unaffected by this split and stay on `/dashboard`. The Deadlines
window SHALL be three days in the full structure and the working week (seven
days, as the week strip shows) in the simple structure. A case whose deadline
is today SHALL be listed as due today, not as overdue (REQ-OTE-05).

#### Scenario: My Work shows open tasks, deadlines and open cases
@e2e exclude a manifest widget declaration; the widgets and their windows are asserted in tests/vitest/simpleListAndDashboard.spec.js

- **WHEN** a handler views My Work in the full structure
- **THEN** the page shows a "My work" widget of the current user's open tasks
- **AND** a "Deadlines" widget of cases due today, overdue, or within 3 days
- **AND** an "Open Cases" widget of the most recently started open cases

#### Scenario: The simple structure shows the week
@e2e exclude a manifest window value; asserted in tests/vitest/simpleListAndDashboard.spec.js

- **WHEN** a handler views My Work in the simple structure
- **THEN** the deadlines shown SHALL be those from today up to seven days ahead

#### Scenario: The Dashboard no longer carries the personal-workload widgets
@e2e exclude a manifest widget declaration; the widgets and their windows are asserted in tests/vitest/simpleListAndDashboard.spec.js

- **WHEN** a handler views `/dashboard`
- **THEN** the page does NOT show the My work, Deadlines or Open Cases widgets
