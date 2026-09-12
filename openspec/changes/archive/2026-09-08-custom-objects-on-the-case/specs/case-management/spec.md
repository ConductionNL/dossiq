## ADDED Requirements

### Requirement: The case page lists its objects (REQ-CM-28)

You see what the case is about on the case. `CaseDetail` SHALL show an
Objects tab in `case-panels` that lists the `caseObject` records whose
`case` is the open case, with the columns object type, identification,
description and link. A case without objects SHALL show the tab with an
empty state, not hide it. The rows SHALL NOT open a detail page: a case
object is a link row, not a record of its own.

**Feature tier**: MVP

#### Scenario: The tab lists the case's objects
@e2e tests/e2e/case-objects.spec.ts

- **GIVEN** a case with a building object and a vehicle object
- **AND** another case with one object of its own
- **WHEN** you open the first case's Objects tab
- **THEN** the list SHALL show two rows with their object type, identification, description and link
- **AND** SHALL NOT show the other case's object

#### Scenario: An empty case shows the tab
@e2e tests/e2e/case-objects.spec.ts

- **GIVEN** a case without objects
- **WHEN** you open its page
- **THEN** the Objects tab SHALL be present
- **AND** SHALL read No objects linked to this case yet

### Requirement: You link an object from the case (REQ-CM-29)

You link a building, a vehicle or any other object without leaving the
case. `CaseDetail` SHALL offer a header action Link object that opens a form
over `caseObject` asking for the object type, the identification, the link
and a description, with the open case passed as the value of `case`. After
saving, the new object SHALL appear in the Objects tab.

**Feature tier**: MVP

#### Scenario: A linked object shows up in the tab
@e2e tests/e2e/case-objects.spec.ts

- **GIVEN** a case with no objects
- **WHEN** you choose Link object, enter object type building, identification 0363100012345678, description The shed at the back and save
- **THEN** the Objects tab SHALL show one row with that object type, identification and description
- **AND** the saved object SHALL hold the case's id in `case`

#### Scenario: A link without an object type is refused
@e2e tests/e2e/case-objects.spec.ts

- **GIVEN** the Link object form is open
- **WHEN** you fill the identification and leave the object type empty and try to save
- **THEN** the form SHALL not save
- **AND** SHALL mark the object type as required

#### Scenario: The case is prefilled once nextcloud-vue passes initial data
@e2e exclude The prefill waits on nextcloud-vue create-with-initial-data (placement.md, triage #6); until then the saved object is asserted, not the field.

- **GIVEN** the Link object form opened from a case
- **WHEN** the form renders
- **THEN** the `case` field SHALL already hold the open case

### Requirement: You find every case on an object (REQ-CM-30)

You start from the object and find its cases. The app SHALL offer an
Objects index over `caseObject`, reached from the Cases menu group, whose
sidebar groups the rows by object type and whose search matches the
identification. Each row SHALL name its case and SHALL offer a View case
action that opens that case. `caseObject.objectType` SHALL be a facet so the
sidebar can group on it.

**Feature tier**: MVP

#### Scenario: One building, two cases
@e2e tests/e2e/case-objects.spec.ts

- **GIVEN** two cases that each link the building 0363100012345678
- **AND** a third case that links a vehicle
- **WHEN** you open the Objects index and search for 0363100012345678
- **THEN** the list SHALL show two rows, one per case
- **AND** SHALL NOT show the vehicle

#### Scenario: A row opens its case
@e2e tests/e2e/case-objects.spec.ts

- **GIVEN** the Objects index showing a row on a case
- **WHEN** you choose View case on that row
- **THEN** the case page of that case SHALL open

#### Scenario: The sidebar groups by object type
@e2e tests/e2e/case-objects.spec.ts

- **GIVEN** objects of type building and of type vehicle
- **WHEN** you pick building in the sidebar
- **THEN** the list SHALL show only the building rows
- **AND** All objects SHALL bring every row back
