# case-management delta

## ADDED Requirements

### Requirement: Objects is reached from the Cases menu

The simple profile SHALL list Objects in the Cases part of the menu, at `/objects`. Each row SHALL be one case on an object: the object first, then its type, the case and the status of that case.

#### Scenario: A handler starts at the object
@e2e exclude a menu entry and columns declared in the manifest; objectsIndexLook reads them

- **GIVEN** a building linked to two cases
- **WHEN** a handler opens Objecten under Zaken
- **THEN** the list shows two rows for that building, each with its case, the case number and the status
- **AND** View case opens that row's case
