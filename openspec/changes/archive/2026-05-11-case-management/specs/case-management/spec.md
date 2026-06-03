---
status: implemented
---
# case-management Specification

## Purpose
Define the foundational case lifecycle on top of OpenRegister: creating, reading, updating, deleting, listing, searching, validating, and presenting case objects with their custom properties and required documents. This spec documents the MVP surface implemented by this change set; suspension, sub-cases, and confidentiality enforcement remain out of scope.

## Context
A `case` (zaak) is the primary domain object in Procest. Cases are stored as OpenRegister objects and rendered through `CaseList.vue` and `CaseDetail.vue` using the shared `createObjectStore` pattern. The list view consumes `_filters` and `_search` query parameters, while detail panels (`CustomPropertiesPanel.vue`, `DocumentChecklist.vue`) read property definitions and document type requirements off the linked case type. Validation rules live in `caseValidation.js`.

## ADDED Requirements
### Requirement: REQ-CM-LIST-01 — Case List Filters
The case list MUST expose filters for handler, priority, and overdue status.

#### Scenario: CM-LIST-01-1: Filter by handler
- **GIVEN** the case list contains cases assigned to multiple handlers
- **WHEN** the user selects a handler from the handler filter dropdown
- **THEN** the list MUST refresh with `_filters[handler]` applied via the object store
- **AND** only cases assigned to the selected handler MUST be visible

#### Scenario: CM-LIST-01-2: Filter by priority
- **WHEN** the user selects a priority value (e.g., "high")
- **THEN** the list MUST refresh with `_filters[priority]` applied
- **AND** only cases matching that priority MUST be visible

#### Scenario: CM-LIST-01-3: Filter overdue cases
- **WHEN** the user activates the "overdue" toggle
- **THEN** the list MUST be filtered to cases whose deadline is in the past and whose status is not final

#### Scenario: CM-LIST-01-4: Combined filters
- **WHEN** the user combines case type, status, handler, priority, and overdue filters
- **THEN** all filters MUST be applied as an AND condition and reflected in the URL query state

### Requirement: REQ-CM-LIST-02 — Case Search
The case list MUST support keyword search across title, description, and identifier.

#### Scenario: CM-LIST-02-1: Search by keyword
- **GIVEN** a case "Omgevingsvergunning verbouwing Kerkstraat 12" with identifier "ZAAK-2026-000123"
- **WHEN** the user types "Kerkstraat" into the search field
- **THEN** the list MUST refresh with `_search=Kerkstraat`
- **AND** the matching case MUST appear in the results

#### Scenario: CM-LIST-02-2: Search by identifier
- **WHEN** the user types a full or partial case identifier
- **THEN** matching cases MUST appear in the results regardless of title content

#### Scenario: CM-LIST-02-3: Empty search
- **WHEN** the search field is cleared
- **THEN** the `_search` parameter MUST be removed and the full filtered list MUST return

### Requirement: REQ-CM-PROP-01 — Custom Properties Panel
The case detail view MUST render a panel showing the case's custom properties as defined by its case type.

#### Scenario: CM-PROP-01-1: Render configured properties
- **GIVEN** a case whose case type defines properties `["aanvraagdatum", "locatie", "bouwsom"]`
- **WHEN** the user opens the case detail view
- **THEN** the custom properties panel MUST render each property with its label and current value
- **AND** properties not defined on the case type MUST NOT appear

#### Scenario: CM-PROP-01-2: Edit a property value
- **WHEN** the user edits a property value through the panel and saves
- **THEN** the new value MUST be persisted on the case object
- **AND** the panel MUST reflect the updated value without a full page reload

#### Scenario: CM-PROP-01-3: Property panel with no definitions
- **GIVEN** a case whose case type defines no custom properties
- **THEN** the panel MUST render an empty state ("Geen aanvullende kenmerken") and remain hidden from primary actions

### Requirement: REQ-CM-DOC-01 — Required Documents Checklist
The case detail view MUST render a checklist showing required document types and their completion status.

#### Scenario: CM-DOC-01-1: Render required documents
- **GIVEN** a case whose case type defines document types with `required=true`
- **WHEN** the user opens the case detail view
- **THEN** the checklist MUST list each required document type with a checkmark when at least one matching case document is present

#### Scenario: CM-DOC-01-2: Missing documents flagged
- **GIVEN** a required document type with no matching case document
- **THEN** the checklist MUST render that row as incomplete with an explicit "Ontbreekt" label

#### Scenario: CM-DOC-01-3: Optional documents excluded
- **GIVEN** a case type that defines optional document types in addition to required ones
- **THEN** only the required types MUST appear in the checklist

### Requirement: REQ-CM-VAL-01 — Strengthened Case Validation
Case creation and update MUST surface explicit validation errors for case type validity, missing title, and missing case type.

#### Scenario: CM-VAL-01-1: Case type not yet valid
- **GIVEN** a case type with `validFrom` in the future
- **WHEN** the user attempts to create a case using it
- **THEN** `caseValidation.js` MUST return an error identifying the case type and its `validFrom` date
- **AND** the form MUST display this error inline next to the case type field

#### Scenario: CM-VAL-01-2: Case type expired
- **GIVEN** a case type with `validTo` in the past
- **WHEN** the user attempts to create a case using it
- **THEN** validation MUST fail with an explicit "vervallen" error referencing the expiration date

#### Scenario: CM-VAL-01-3: Missing required fields
- **GIVEN** a case create form without title or case type
- **WHEN** the user submits
- **THEN** each missing required field MUST be highlighted with a Dutch-language error message
- **AND** the save action MUST be blocked until errors are resolved

### Requirement: REQ-CM-CRUD-01 — Case CRUD Foundation
The system MUST support create, read, update, and delete operations on case objects through the shared object store.

#### Scenario: CM-CRUD-01-1: Create case
- **GIVEN** a valid case type and title
- **WHEN** the user submits the create form
- **THEN** a new case object MUST be persisted via `createObjectStore('case').save(...)`
- **AND** the user MUST be redirected to the new case's detail view

#### Scenario: CM-CRUD-01-2: Update case
- **WHEN** the user edits a case field (e.g., description, priority, handler) and saves
- **THEN** the update MUST be persisted and the detail view MUST reflect the new value

#### Scenario: CM-CRUD-01-3: Delete case in initial status
- **GIVEN** a case in its initial status with no dependent links
- **WHEN** the user confirms deletion
- **THEN** the case object MUST be deleted via the object store
- **AND** the user MUST be returned to the case list

### Requirement: REQ-CM-INT-01 — Custom Properties and Document Checklist Integration
The case detail view MUST integrate the custom properties panel and the document checklist alongside existing panels.

#### Scenario: CM-INT-01-1: Panels visible on detail view
- **GIVEN** a case with custom properties and required documents
- **WHEN** the user opens the detail view
- **THEN** both `CustomPropertiesPanel.vue` and `DocumentChecklist.vue` MUST be visible
- **AND** they MUST coexist with the status timeline, deadline, activity, and tasks panels without layout overlap

## Non-Requirements
- Case suspension (out of scope, deferred to V1)
- Sub-cases / parent-child case relations (deferred to V1)
- Confidentiality (`vertrouwelijkheidaanduiding`) enforcement at view-time (deferred to V1)
- Status blocking by missing properties or documents (deferred to V1)

## Dependencies
- OpenRegister `case` schema and shared `createObjectStore`
- `CaseList.vue`, `CaseDetail.vue`, `CustomPropertiesPanel.vue`, `DocumentChecklist.vue`
- `caseValidation.js` validation utility
- `case-types` capability for property definitions and document type definitions
