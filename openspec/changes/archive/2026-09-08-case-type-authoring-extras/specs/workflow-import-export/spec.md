## ADDED Requirements

### Requirement: You export a case type and import one from its page (REQ-WIE-01)

You export a case type and import one from the case type page. The page
SHALL offer Export, Import and Duplicate as header actions over
`CaseDefinitionController`. Export SHALL download the bundle the API
already produces; Import SHALL take a bundle file; Duplicate SHALL open the
copy.

**Feature tier**: MVP

#### Scenario: Duplicate opens the copy
@e2e tests/e2e/case-type-authoring-extras.spec.ts

- **GIVEN** the type Bezwaar
- **WHEN** you choose Duplicate
- **THEN** you SHALL land on a type titled Bezwaar (kopie) with the same statuses

#### Scenario: Export downloads the bundle
@e2e tests/e2e/case-type-authoring-extras.spec.ts

- **GIVEN** the type Bezwaar
- **WHEN** you choose Export
- **THEN** a download SHALL start whose name carries the type's identifier

#### Scenario: Import takes a bundle
@e2e exclude The import needs an OS file dialog; covered by CaseDefinitionControllerTest.

- **GIVEN** a bundle exported from another instance
- **WHEN** you choose Import and pick the file
- **THEN** the imported type SHALL be listed on the Case types index
