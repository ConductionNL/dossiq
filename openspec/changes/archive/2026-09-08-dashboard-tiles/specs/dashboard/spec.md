# dashboard (delta)

## ADDED Requirements

### Requirement: One work table with days left and row actions (REQ-DASH-019)
The Dashboard page SHALL show your open tasks in one table with the columns title, case and days left. You see each task once. Each row SHALL offer Pick up and Complete as row actions where the widget vocabulary supports them, and SHALL open the task otherwise.

#### Scenario: Your tasks appear once with days left
- **GIVEN** you have three open tasks, one of them due tomorrow
- **WHEN** you open the Dashboard
- **THEN** the My work table lists the three tasks once each, and the row due tomorrow reads "1 days remaining" or "Due today" depending on the clock
- @e2e covered by `tests/e2e/dashboard-tiles.spec.ts` (tasks.md 3.1)

#### Scenario: You complete a task from the row
- **GIVEN** a task in status active assigned to you
- **WHEN** you choose Complete on its row
- **THEN** the task leaves the table and the case's task count drops by one
- @e2e exclude blocked on nextcloud-vue row actions for object-table; until then the row opens TaskDetail, whose lifecycle buttons `tests/e2e/case-detail-kpis-and-tabs.spec.ts` already covers

### Requirement: View all keeps the tile's filter (REQ-DASH-020)
Every table on the Dashboard SHALL carry a View all link whose route query equals the table's own filter. You land on a list showing the same rows the tile showed, not the whole index.

#### Scenario: View all from the deadlines table
- **GIVEN** nine cases past their deadline and thirty open cases
- **WHEN** you follow View all on the Deadlines table
- **THEN** the Cases index opens with the deadline filter applied and lists the cases within the deadline window only
- @e2e covered by `tests/e2e/dashboard-tiles.spec.ts` (tasks.md 3.1)

### Requirement: KPI tiles render on a fresh load (REQ-DASH-021)
The five stat tiles SHALL render their values on the first load of the Dashboard in a new browser session. You never see "Widget not available" for a tile whose endpoint answers.

#### Scenario: Fresh session lands on the Dashboard
- **GIVEN** a new browser context with no page visited before
- **WHEN** you open `/apps/dossiq/`
- **THEN** the tiles Open cases, Overdue, Completed this month, My tasks and SLA compliance each show a number
- @e2e covered by `tests/e2e/dashboard-tiles.spec.ts` (tasks.md 3.1)

### Requirement: The case type list on New case is sorted and filtered (REQ-DASH-022)
The New case form SHALL list case types ordered by title, without drafts and without types whose validity has ended. You pick from a list you can scan.

**Build note (task 2.5).** Only the drafts clause is shipped. The other two need an upstream change and are recorded in design D5 and tasks 2.5a / 2.5b, so the one scenario below is split rather than left claiming coverage it does not have.

#### Scenario: Draft case types are absent
- **GIVEN** a draft case type and a published one
- **WHEN** you open New case and expand the case type field
- **THEN** the draft does not appear and the published one does
- @e2e covered by `tests/e2e/dashboard-tiles.spec.ts` (tasks.md 3.1)

#### Scenario: Expired types are absent, and the rest are alphabetical
- **GIVEN** a case type whose `validUntil` is yesterday and a case type with no `validUntil`
- **WHEN** you open New case and expand the case type field
- **THEN** the expired one is absent, the open-ended one is present, and the list reads alphabetically
- @e2e exclude blocked upstream twice over. OpenRegister's filter grammar ANDs every operator on a property with no OR key, so "validUntil is null OR on or after today" cannot be expressed and the achievable `validUntil[gte]` would drop every open-ended type instead. Separately, nextcloud-vue's reference picker sends `_limit` and the relation filter only, never `_order`, so the list cannot be sorted from either the manifest or the schema. Shipping either as a manifest key would validate, ship and do nothing. See design D5, tasks 2.5a and 2.5b.

#### Scenario: Recently used types come first
- **GIVEN** you created a case of type Melding openbare ruimte last week
- **WHEN** you open New case
- **THEN** that type is at the top of the list
- @e2e exclude blocked on nextcloud-vue `recentFirst` for a reference field; no dossiq-side interim, the sorted list is the behaviour until then
