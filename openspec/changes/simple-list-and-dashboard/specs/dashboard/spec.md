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
- **GIVEN** the simple structure and a handler with no deadline today
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
