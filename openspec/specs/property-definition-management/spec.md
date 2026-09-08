---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# property-definition-management Specification

## Purpose
Provides admin tabs for managing the property definitions, document types, and decision types attached to a case type, completing the seven-tab case-type detail view. Property definitions declare domain-specific required fields with format validation, document types define a required-document checklist with direction and confidentiality classification, and decision types define the formal decision categories that can be recorded on cases.

## Requirements

### Requirement: Property Definition Management Tab
The system SHALL provide an admin tab for managing custom property definitions on a
case type. Property definitions specify domain-specific required fields with format
validation.

#### Scenario: View property definitions tab
- GIVEN an admin editing case type "Omgevingsvergunning"
- WHEN they click the "Properties" tab
- THEN the system MUST display all property definitions linked to this case type
- AND each row MUST show: name, propertyType (badge), isRequired (icon)
- AND an "Add" button MUST be visible

#### Scenario: Add a property definition
- GIVEN an admin on the Properties tab for case type "Omgevingsvergunning"
- WHEN they click "Add" and submit Name "Kadastraal perceelnummer",
  propertyType "text", isRequired true
- THEN the system MUST create a `propertyDefinition` OpenRegister object linked to the current case type
- AND the new property MUST appear in the tab list

#### Scenario: Property type options
- GIVEN the property definition creation form
- WHEN the admin opens the `propertyType` dropdown
- THEN the options MUST include: text, number, date, datetime

#### Scenario: Edit a property definition
- GIVEN a property definition "Kadastraal perceelnummer" with `isRequired = false`
- WHEN the admin sets `isRequired = true`
- THEN the property definition MUST be updated

#### Scenario: Delete a property definition
- GIVEN a property definition "Bouwlagen" not used on any active cases
- WHEN the admin deletes it
- THEN the property definition MUST be removed

### Requirement: Document Type Management Tab
The system SHALL provide an admin tab for managing required document types on a
case type. Document types define a required document checklist with direction
classification.

#### Scenario: View document types tab
- GIVEN an admin editing case type "Omgevingsvergunning"
- WHEN they click the "Docs" tab
- THEN the system MUST display all document types linked to this case type
- AND each row MUST show: name, category, isRequired (icon), confidentiality
- AND an "Add" button MUST be visible

#### Scenario: Add a document type
- GIVEN an admin on the Docs tab for case type "Omgevingsvergunning"
- WHEN they click "Add" and submit Name "Bouwtekening", isRequired true,
  allowedMimeTypes ["application/pdf", "image/png", "image/jpeg"]
- THEN the system MUST create a `documentType` OpenRegister object linked to the current case type
- AND the new document type MUST appear in the tab list

#### Scenario: Delete a document type preserves files
- GIVEN a document type "Situatietekening" on case type "Omgevingsvergunning"
- WHEN the admin deletes it
- THEN the document type requirement MUST be removed from the case type
- AND existing uploaded files matching this type MUST NOT be deleted

### Requirement: Decision Type Management Tab
The system SHALL provide an admin tab for managing allowed decision types on a case
type. Decision types define the formal decision categories that can be recorded on
cases.

#### Scenario: View decision types tab
- GIVEN an admin editing case type "Omgevingsvergunning"
- WHEN they click the "Decisions" tab
- THEN the system MUST display all decision types linked to this case type
- AND each row MUST show: name, publicationRequired (icon), isDraft (badge), validFrom
- AND an "Add" button MUST be visible

#### Scenario: Add a decision type
- GIVEN an admin on the Decisions tab for case type "Omgevingsvergunning"
- WHEN they click "Add" and submit Name "Vergunningsbesluit",
  publicationRequired true, isDraft false, validFrom "2026-01-01"
- THEN the system MUST create a `decisionType` OpenRegister object linked to the current case type
- AND the new decision type MUST appear in the tab list

### Requirement: All seven case type tabs MUST render together
The system SHALL complete the case type detail view with all seven tabs integrated
and functional.

#### Scenario: Full seven-tab detail view
- GIVEN an admin editing case type "Omgevingsvergunning"
- WHEN the detail view loads
- THEN all seven tabs (General, Statuses, Results, Roles, Properties, Docs,
  Decisions) MUST render without console errors
- AND switching to any sub-entity tab MUST fetch the correct sub-entities scoped to the case type

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
