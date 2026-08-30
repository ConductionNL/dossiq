# property-definition-management Specification

## ADDED Requirements

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
