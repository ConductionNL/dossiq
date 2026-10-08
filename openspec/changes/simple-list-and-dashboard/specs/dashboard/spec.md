## ADDED Requirements

### Requirement: The simple dashboard opens with the handler's own day (REQ-DASH-025)
In the simple structure the dashboard MUST first show a greeting, a card for
deadlines that end today, four counts of the handler's own cases, the deadlines
of this week, the handler's cases per step and the handler's tasks. Every
widget the dashboard held before MUST still be on the page. The full structure
MUST keep the dashboard unchanged.

#### Scenario: A handler with a deadline today
@e2e exclude Needs seeded cases with today's deadline on a live instance; the widgets, their filters and the layout are asserted in simpleListAndDashboard.spec.js, and the coordinator checks the page live.
- **GIVEN** the simple structure and a handler with an open case whose deadline is today
- **WHEN** they open the dashboard
- **THEN** the card "First today" MUST be shown and MUST link to those cases
- **AND** the week strip MUST mark that case as late

#### Scenario: A handler without a deadline today
@e2e exclude Same reason; the card's condition is asserted in simpleListAndDashboard.spec.js.
- **GIVEN** the simple structure and a handler with no case past its deadline or ending today
- **WHEN** they open the dashboard
- **THEN** the card "First today" MUST NOT be shown

#### Scenario: Nothing the dashboard held is lost
@e2e exclude A comparison of the built page with the manifest, asserted in simpleListAndDashboard.spec.js.
- **GIVEN** the simple structure
- **WHEN** the dashboard is built
- **THEN** every widget and every layout entry it had MUST still be there

### Requirement: The simple cases list leads with five counted views and six columns (REQ-DASH-026)
In the simple structure the cases list MUST lead with the views All, Mine, Due
this week, Waiting on the applicant and Woo requests, each with a count, and
MUST keep every other lens reachable. It MUST show the columns number, title,
type, status, handler and deadline.

#### Scenario: Five views and the rest behind the chip
@e2e exclude The counts need library 2.60.0 on a live instance; the lenses, their order and their filters are asserted in simpleListAndDashboard.spec.js.
- **GIVEN** the simple structure
- **WHEN** a handler opens the cases list
- **THEN** the strip MUST show the five views with a count each
- **AND** the other fifteen lenses MUST be offered behind the overflow chip

### Requirement: A deadline that ends today is late in the simple structure (REQ-DASH-027)
In the simple structure a case whose deadline is today MUST be marked late on
the board, in the list and in the week strip. In the full structure the board
MUST keep its own rule.

#### Scenario: A card due today
@e2e exclude The rule and the card's reading of it are asserted in simpleListAndDashboard.spec.js.
- **GIVEN** the simple structure and a case whose deadline is today
- **WHEN** a handler opens the board
- **THEN** the card MUST be marked overdue

#### Scenario: The full structure keeps its rule
@e2e exclude Asserted in simpleListAndDashboard.spec.js.
- **GIVEN** the full structure and a case whose deadline is today
- **WHEN** a handler opens the board
- **THEN** the card MUST show a warning, not overdue

### Requirement: The simple pages draw the Zuiddrecht boards' header, cards and footer (REQ-DASH-028)
In the simple structure the dashboard MUST draw the greeting on the page ground
with a "My work | My team" switch, MUST draw First today without a second card
around it, MUST draw the four counts stacked and MUST show no widget Actions
menus. The case page MUST draw its header as a card holding the stages, and
MUST place the banner stack in the side column. The cases list MUST show its
title, the count of rows and the case number under the title. The board MUST
NOT offer a Dashboard button. The navigation footer MUST hold Settings and Help
only. The full structure MUST keep every one of these pages as it was.

#### Scenario: The dashboard header and First today
@e2e exclude Library opt-ins asserted on the built manifest in simpleListAndDashboard.spec.js; the coordinator compares the page with DqDashboard live.
- **GIVEN** the simple structure
- **WHEN** a handler opens the dashboard
- **THEN** the greeting MUST sit on the page ground with My work and My team at its right
- **AND** My team MUST open the team queue
- **AND** First today MUST be drawn without a card around its own card

#### Scenario: The case page header
@e2e exclude Asserted on the built manifest in simpleCasePage.spec.js; compared with DqZaak live.
- **GIVEN** the simple structure
- **WHEN** a handler opens a case
- **THEN** the stages MUST sit inside the header card
- **AND** favourite, follow and attention MUST be in the side column, not above the tabs

#### Scenario: The full structure is unchanged
@e2e exclude A comparison of the built pages with the manifest, asserted in simpleListAndDashboard.spec.js and simpleCasePage.spec.js.
- **GIVEN** the full structure
- **WHEN** the pages are built
- **THEN** the dashboard, the case page, the list and the board MUST equal what the manifest declares
