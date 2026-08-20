# Spec: zaaktype-copy

## ADDED Requirements

### Requirement: Deep-copy a case type into a new draft

The system SHALL provide `POST /api/case-definitions/{id}/copy`, which
creates a brand-new `caseType` OpenRegister object seeded from an existing
one, together with copies of every owned sub-object (`statusType`,
`resultType`, `roleType`, `propertyDefinition`, `documentType`,
`decisionType`) re-pointed at the new case type's id. The source case type
is left untouched.

#### Scenario: Duplicate a published case type

- **GIVEN** a published case type "Omgevingsvergunning regulier" with 3
  status types and 2 property definitions
- **WHEN** an admin calls `POST /api/case-definitions/{id}/copy` for that
  case type
- **THEN** the system MUST create a new `caseType` object with a new id
- **AND** the new case type's title MUST be "Copy of Omgevingsvergunning
  regulier"
- **AND** the new case type's `isDraft` MUST be `true`
- **AND** the system MUST create 3 new `statusType` objects and 2 new
  `propertyDefinition` objects, each with `caseType` set to the new case
  type's id
- **AND** the original case type and its sub-objects MUST be unchanged

#### Scenario: Copy clears publication and version-pinning fields

- **GIVEN** a published case type with `publicationRequired: true`,
  `publicationText: "..."`, and a `workflowDefinition` pinned to a specific
  workflow version
- **WHEN** it is copied
- **THEN** the new case type's `publicationRequired` MUST be `false`
- **AND** the new case type's `publicationText` MUST be empty
- **AND** the new case type's `workflowDefinition` MUST NOT reference the
  source's pinned workflow version

#### Scenario: Copy does not inherit sibling case-type links

- **GIVEN** a case type with `relatedCaseTypes` and `subCaseTypes` pointing
  at other case types
- **WHEN** it is copied
- **THEN** the new case type's `relatedCaseTypes` MUST be empty
- **AND** the new case type's `subCaseTypes` MUST be empty

#### Scenario: Copying a non-existent case type returns 404

- **GIVEN** no case type exists with id "does-not-exist"
- **WHEN** `POST /api/case-definitions/does-not-exist/copy` is called
- **THEN** the system MUST respond with HTTP 404
- **AND** MUST NOT create any new objects

### Requirement: Guard case-type deletion to draft status

The system SHALL provide `DELETE /api/case-definitions/{id}`, which deletes
a case type only when it is a draft (`isDraft === true`). A published case
type MUST NOT be deleted through this endpoint.

#### Scenario: Delete a draft case type

- **GIVEN** a draft case type "Testtype" with `isDraft: true`
- **WHEN** an admin calls `DELETE /api/case-definitions/{id}` for that case
  type
- **THEN** the system MUST delete the case type
- **AND** MUST respond with a success status

#### Scenario: Deleting a published case type is blocked

- **GIVEN** a published case type "Omgevingsvergunning regulier" with
  `isDraft: false`
- **WHEN** an admin calls `DELETE /api/case-definitions/{id}` for that case
  type
- **THEN** the system MUST respond with HTTP 409
- **AND** MUST NOT delete the case type

#### Scenario: Deleting a non-existent case type returns 404

- **GIVEN** no case type exists with id "does-not-exist"
- **WHEN** `DELETE /api/case-definitions/does-not-exist` is called
- **THEN** the system MUST respond with HTTP 404

### Requirement: Duplicate action in the case-type admin UI

The case-type list and case-type detail views SHALL offer a "Duplicate"
action for existing case types. On success the admin MUST be navigated to
the newly created draft.

#### Scenario: Duplicate from the case-type list

- **GIVEN** an admin viewing the case-type list containing "Omgevingsvergunning
  regulier"
- **WHEN** they click "Duplicate" on that row
- **THEN** the system MUST call the copy endpoint for that case type
- **AND** MUST navigate to the detail view of the newly created draft
  "Copy of Omgevingsvergunning regulier"

#### Scenario: Duplicate from the case-type detail view

- **GIVEN** an admin viewing the detail page of an existing case type
- **WHEN** they click "Duplicate" in the header actions
- **THEN** the system MUST call the copy endpoint for that case type
- **AND** MUST navigate to the detail view of the newly created draft

#### Scenario: Delete action respects the draft-only guard

- **GIVEN** an admin viewing the case-type list containing a published case
  type
- **WHEN** they click "Delete" on that row and confirm
- **THEN** the system MUST call the guarded delete endpoint
- **AND** on a 409 response MUST show an error explaining the case type
  must be unpublished before it can be deleted
- **AND** MUST NOT remove the row from the list
