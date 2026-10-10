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
