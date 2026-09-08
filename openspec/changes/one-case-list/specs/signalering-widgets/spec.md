## ADDED Requirements

### Requirement: Countdown Deadline Column [V1]

You see the days left on each row and filter the list on overdue cases. The
Deadline column on the `Cases` page SHALL render through the countdown cell:
the number of days left until `deadline`, and once the deadline is past the
cell SHALL read as overdue in the signalering red the dashboard tiles use,
with the count of days past. A case without a deadline SHALL show an empty
cell, not zero. The `quickFilters` chip Overdue (`deadline lt @today`,
`isFinalStatus = false`) SHALL show the same cases the Overdue dashboard
tile counts, and the tile's View all SHALL lead to the Cases page carrying
that same filter, so it no longer drops on the way (triage item 4). The
column SHALL sort on `deadline`, not on the rendered text.

**The chip cannot be activated FROM the query** in `@conduction/nextcloud-vue`
2.41: `resolveInitialQuickFilterIndex` reads only the `default` flag, so a
reader arriving from the tile lands on the All chip with the tile's filter
applied through the route query. The list is right and the filter is in the
URL; what is missing is the chip lighting up to say which lens is on. The
requirement therefore asks for the filter to survive the trip, which is the
defect triage item 4 named, and naming a chip from a query is left to a
nextcloud-vue change.

#### Scenario: Days left on each row
@e2e tests/e2e/case-list-lenses.spec.ts

- **GIVEN** an open case assigned to the signed-in user due in 3 days
- **WHEN** you open the Cases page
- **THEN** the Deadline cell of that case SHALL read 3 days left

#### Scenario: Past due reads red
@e2e tests/e2e/case-list-lenses.spec.ts

- **GIVEN** an open case assigned to the signed-in user whose deadline was 2 days ago
- **WHEN** you open the Cases page
- **THEN** the Deadline cell SHALL read 2 days overdue
- **AND** the cell SHALL carry the overdue styling class

#### Scenario: Overdue shows only open cases past their deadline
@e2e tests/e2e/case-list-lenses.spec.ts

- **GIVEN** an open case past its deadline, a closed case past its deadline and an open case due in 3 days
- **WHEN** you choose the chip Overdue
- **THEN** the list SHALL show the open overdue case only

#### Scenario: The Overdue tile keeps its filter
@e2e tests/e2e/case-list-lenses.spec.ts

- **GIVEN** the dashboard with the Overdue tile counting 1 case
- **WHEN** you follow the tile's View all
- **THEN** the Cases page SHALL open carrying the tile's filter
- **AND** the list SHALL show 1 case, the open overdue one
