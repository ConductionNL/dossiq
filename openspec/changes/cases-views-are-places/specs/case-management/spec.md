## ADDED Requirements

### Requirement: A saved view of cases is a place (REQ-CM-44)

`#Cases` and `#Queue` SHALL declare that their saved views are places.
Each saved view on those pages SHALL therefore have a route of its own,
SHALL offer the presentations its view config declares, and SHALL take an
entry under its page's navigation entry when a user pins it. No other
dossiq page SHALL declare places, and dossiq SHALL seed no pinned view.

`#Tasks` SHALL NOT declare places while its saved views are off. Its list
is the engine's task inbox rather than a search over a register, and a
place whose page has no saved views is a route that can only ever open an
empty state.

#### Scenario: a view opens from its own address
@e2e tests/e2e/cases-views-are-places.spec.ts

- **GIVEN** a saved view Overdue on Cases
- **WHEN** a handler opens its address in a new tab
- **THEN** the view SHALL render its list

#### Scenario: the link is to the view, not to the shape it was read in
@e2e tests/e2e/cases-views-are-places.spec.ts

- **GIVEN** a saved view open at its own address
- **WHEN** the handler switches presentation
- **THEN** the address SHALL still name the view

#### Scenario: a link written before views had addresses still lands
@e2e tests/e2e/cases-views-are-places.spec.ts

- **GIVEN** a link of the form `/cases?view=<id>`
- **WHEN** it is opened
- **THEN** the browser SHALL end on that view's own address

#### Scenario: a view that is gone says which one
@e2e tests/e2e/cases-views-are-places.spec.ts

- **GIVEN** an address naming a view that has been deleted
- **WHEN** it is opened
- **THEN** the page SHALL name the view rather than render an empty list

#### Scenario: pinning puts it under its page
@e2e tests/e2e/cases-views-are-places.spec.ts

- **GIVEN** a saved view on Cases
- **WHEN** the handler pins it
- **THEN** it SHALL appear in the navigation under Cases
- **AND** no top-level navigation entry SHALL be added

#### Scenario: a fresh install has the navigation it has today
@e2e tests/e2e/cases-views-are-places.spec.ts

- **GIVEN** a fresh install with no pinned views
- **WHEN** the app loads
- **THEN** the navigation SHALL hold no view entry

#### Scenario: pages that are not worked from do not declare places

- **GIVEN** the case types page
- **WHEN** its manifest is read
- **THEN** it SHALL NOT declare saved-view places

#### Scenario: the task inbox declares no places while it saves no views

- **GIVEN** the Tasks page, whose saved views are off
- **WHEN** its manifest is read
- **THEN** it SHALL NOT declare saved-view places
