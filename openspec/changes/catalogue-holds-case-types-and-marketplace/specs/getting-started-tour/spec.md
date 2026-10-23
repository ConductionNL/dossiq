# getting-started-tour Delta: catalogue-holds-case-types-and-marketplace

**Status**: draft

## MODIFIED Requirements

### Requirement: A tour task names the menu label its target carries

Every step of the `dossiq:getting-started` tour that targets a navigation item
and has a task SHALL name that item by the label it carries in the menu, in
both the simple and the full structure. When the item sits in the Advanced
foldout, the task SHALL say to open Advanced first.

#### Scenario: The cases step in the simple structure
@e2e exclude The task wording is data in the manifest; tests/vitest/tourMenuLabels.spec.js checks every nav-item task against the label its target carries in both built menus.


- **GIVEN** the simple menu structure, where "Cases" is a caption and the item is "All cases"
- **WHEN** the tour reaches the cases step
- **THEN** the task reads "Click All cases in the menu"

#### Scenario: The case types step
@e2e exclude The task wording is data in the manifest; tests/vitest/tourMenuLabels.spec.js checks every nav-item task against the label its target carries in both built menus.


- **GIVEN** either menu structure, with the Catalogue in the Advanced foldout and the case types as its first folder
- **WHEN** the tour reaches the case types step
- **THEN** the task reads "Open Advanced, then Catalogue"
- **AND** the step text says the case types are the catalogue's first folder

#### Scenario: A renamed menu item
@e2e exclude The task wording is data in the manifest; tests/vitest/tourMenuLabels.spec.js checks every nav-item task against the label its target carries in both built menus.


- **GIVEN** a menu item whose label changes
- **WHEN** a tour task still names the old label
- **THEN** `tests/vitest/tourMenuLabels.spec.js` fails
