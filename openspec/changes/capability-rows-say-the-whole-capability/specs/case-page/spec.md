# case-page

## ADDED Requirements

### Requirement: The case page shows its task panel beside the tabs (REQ-CRS-17)

The case page SHALL show the case's tasks in a panel beside the tabs, not inside one of them. The panel SHALL stay in view while the reader switches tabs.

Rows: `3.18` (dossiq matrix).

#### Scenario: The panel stays while tabs change
- **GIVEN** a case with two open tasks
- **WHEN** you open the Documents tab and then the History tab
- **THEN** the task panel beside the tabs lists both tasks the whole time

### Requirement: A case type's page offers every widget kind the matrix names (REQ-CRS-46)

An administrator SHALL be able to place each of these widget kinds on a case type's page: fields, table, collection, form, highlight, image, map, metro line, person card, text, divider and a custom widget. Each kind SHALL render from the case's own data, and a kind whose data is absent SHALL show its empty state instead of an error.

#### Scenario: An administrator places a metro line and a person card
- **GIVEN** a case type whose page has one widget tab
- **WHEN** the administrator adds a metro line widget and a person card widget to that tab and publishes the case type
- **THEN** a case of that type SHALL show the metro line with its statuses and the person card of its requester

#### Scenario: A widget without data shows its empty state
- **GIVEN** a case page with an image widget bound to a field the case leaves empty
- **WHEN** a handler opens the case
- **THEN** the image widget SHALL show its empty state and the other widgets SHALL render
