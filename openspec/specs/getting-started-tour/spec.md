# getting-started-tour Specification

## Purpose
The getting-started tour walks a new user through dossiq's menu. This capability keeps every tour step pointing at something the user can actually see and click, by the name the menu gives it.

## Requirements

### Requirement: A tour task names the menu label its target carries

Every step of the `dossiq:getting-started` tour that targets a navigation item
and has a task SHALL name that item by the label it carries in the menu, in
both the simple and the full structure. When the item sits in the Advanced
foldout, the task SHALL say to open Advanced first.

#### Scenario: The cases step in the simple structure

- **GIVEN** the simple menu structure, where "Cases" is a caption and the item is "All cases"
- **WHEN** the tour reaches the cases step
- **THEN** the task reads "Click All cases in the menu"

#### Scenario: The case types step

- **GIVEN** either menu structure, with Case types in the Advanced foldout
- **WHEN** the tour reaches the case types step
- **THEN** the task reads "Open Advanced, then Case types"

#### Scenario: A renamed menu item

- **GIVEN** a menu item whose label changes
- **WHEN** a tour task still names the old label
- **THEN** `tests/vitest/tourMenuLabels.spec.js` fails

### Requirement: The tour says only what the page shows

A step of the `dossiq:getting-started` tour SHALL NOT state a count the
instance does not hold, SHALL name a page the way the page names itself, and
SHALL describe the list it points at as that list describes itself.

#### Scenario: The case types step
<!-- @e2e exclude Copy contract; proven by tests/vitest/tourTellsTheTruth.spec.js. -->
- **WHEN** the tour reaches the case types step
- **THEN** its text names no number of case types

#### Scenario: The last step
<!-- @e2e exclude Copy contract; proven by tests/vitest/tourTellsTheTruth.spec.js. -->
- **WHEN** the tour reaches its last step
- **THEN** title and text both say dashboard, the label of the Dashboard menu item

#### Scenario: The cases step
<!-- @e2e exclude Copy contract; proven by tests/vitest/tourTellsTheTruth.spec.js. -->
- **GIVEN** the cases list says it shows the open cases in your teams
- **WHEN** the tour reaches the cases step
- **THEN** its text says open cases in your teams, and not every case, open and closed

### Requirement: The getting-started tour MUST start only from the dashboard

The getting-started tour SHALL auto-start only on the Dashboard route. A user whose first visit is a deep link SHALL see the page they opened, and SHALL get the tour when they first open the dashboard.

#### Scenario: First visit through a link to a Woo request

- **GIVEN** a staff user who never saw the tour
- **WHEN** they open a link to a Woo request
- **THEN** the case page shows without the welcome card
- **AND** when they open the dashboard the tour starts at "Welcome to Dossiq"
