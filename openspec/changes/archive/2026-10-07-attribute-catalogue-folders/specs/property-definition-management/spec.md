## ADDED Requirements

### Requirement: Attributes are grouped in folders (REQ-PDM-01)

`propertyDefinition` SHALL carry a facetable `category`. The property
definitions index SHALL show a folder sidebar on it with an All attributes
entry, and rows without a category SHALL be listed under Uncategorised.

#### Scenario: Attributes by folder
@e2e tests/e2e/attribute-catalogue-folders.spec.ts

- **GIVEN** attributes in categories Address and Finance and one without
- **WHEN** you open the property definitions index
- **THEN** the sidebar SHALL list All attributes, Address, Finance and Uncategorised
- **AND** picking Finance SHALL list only its attributes

### Requirement: The case type property picker groups by category (REQ-PDM-02)

The property picker on `#CaseTypeDetail` SHALL group attributes by
`category` with the category as a heading.

#### Scenario: Grouped picker
@e2e tests/e2e/attribute-catalogue-folders.spec.ts

- **GIVEN** the same attributes
- **WHEN** you add a property to a case type
- **THEN** the picker SHALL show Address and Finance as headings with their attributes underneath
