## ADDED Requirements

### Requirement: A saved view of cases is a place (REQ-CM-44)

`#Cases`, `#Queue` and `#Tasks` SHALL declare that their saved views are
places. Each saved view on those pages SHALL therefore have a route of its
own, SHALL offer the presentations its view config declares, and SHALL
take an entry under its page's navigation entry when a user pins it. No
other dossiq page SHALL declare places, and dossiq SHALL seed no pinned
view.

#### Scenario: a view opens from its own address
@e2e tests/e2e/cases-views-are-places.spec.ts

- **GIVEN** a saved view Overdue on Cases
- **WHEN** a handler opens its address in a new tab
- **THEN** the view SHALL render its list

#### Scenario: the seeded views open in the right shape
@e2e tests/e2e/cases-views-are-places.spec.ts

- **GIVEN** the seeded Overdue view and the seeded desk view
- **WHEN** each is opened
- **THEN** Overdue SHALL open as a list and the desk view SHALL open as a board

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
