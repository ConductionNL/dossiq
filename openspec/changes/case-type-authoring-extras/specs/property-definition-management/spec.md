## ADDED Requirements

### Requirement: You group case types in folders (REQ-PDM-01)

You group case types in folders. The `caseType` schema SHALL carry
`category`, a free word. The Case types index SHALL show a folder sidebar
over the categories in use, with All case types on top.

**Feature tier**: MVP

#### Scenario: A folder narrows the index
@e2e tests/e2e/case-type-authoring-extras.spec.ts

- **GIVEN** two types in the category Vergunningen and five in others
- **WHEN** you pick Vergunningen in the folder sidebar
- **THEN** the index SHALL list the two only

### Requirement: You reuse one attribute across case types (REQ-PDM-02)

You reuse one attribute across case types. A `propertyDefinition` SHALL be
valid without a `caseType`; such a row is shared. The Properties tab of a
case type SHALL list the type's own attributes first and the shared ones
under Shared attributes.

**Feature tier**: MVP

#### Scenario: A shared attribute appears on every type
@e2e tests/e2e/case-type-authoring-extras.spec.ts

- **GIVEN** an attribute Kenteken saved without a case type
- **WHEN** you open the Properties tab of any case type
- **THEN** Kenteken SHALL be listed under Shared attributes
