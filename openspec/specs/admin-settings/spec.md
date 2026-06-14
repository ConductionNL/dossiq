---
status: done
retrofit_extensions:
  - REQ-ADMIN-016
  - REQ-ADMIN-017
---

# Admin Settings Specification

## Purpose

The admin settings page provides a Nextcloud admin panel for configuring Procest. Administrators manage case types and all their related type definitions: statuses, results, roles, properties, documents, and decisions. The case type system is the behavioral engine of Procest -- every aspect of how a case behaves (allowed statuses, deadlines, required fields, archival rules) is defined here. The admin settings UI follows a list-detail pattern: a case type list on the main page, and a tabbed detail/edit view per case type.

**Feature tiers**: MVP (admin page registration, access control, case type list, case type CRUD, status type CRUD with reorder, default case type, publish action, general tab); V1 (results tab, roles tab, properties tab, documents tab, decisions tab, case type versioning, import/export)

**Competitive context**: Dimpact ZAC provides per-zaaktype configuration with parameters, mail templates, reference tables, and an inrichtingscheck validation system. xxllnc Zaken supports case type versioning with draft/active states and template-based folder hierarchies. Flowable provides visual CMMN/BPMN modelers for case type design. Procest takes a simpler, form-based approach that is more accessible to non-technical administrators while maintaining ZGW-compliant data structures.

## Data Sources

All admin settings data is stored as OpenRegister objects in the `procest` register:
- **Case types**: schema `caseType`
- **Status types**: schema `statusType` (linked to caseType via `caseType` reference)
- **Result types**: schema `resultType` (linked to caseType via `caseType` reference)
- **Role types**: schema `roleType` (linked to caseType via `caseType` reference)
- **Property definitions**: schema `propertyDefinition` (linked to caseType via `caseType` reference)
- **Document types**: schema `documentType` (linked to caseType via `caseType` reference)
- **Decision types**: schema `decisionType` (linked to caseType via `caseType` reference)

## Requirements

### Requirement: Nextcloud Admin Panel Registration [MVP]

The system MUST register a settings page in the Nextcloud admin panel under the standard administration section, using the `AdminSettings` and `SettingsSection` classes to integrate with Nextcloud's settings framework.

#### Scenario: Admin settings page is accessible
- GIVEN a Nextcloud admin user
- WHEN they navigate to Administration settings
- THEN a "Procest" entry MUST appear in the admin settings navigation
- AND clicking "Procest" MUST display the Procest admin settings page
- AND the page MUST render the `AdminRoot.vue` component with case type management and ZGW API mapping sections

#### Scenario: Regular users cannot access admin settings
- GIVEN a regular (non-admin) Nextcloud user
- WHEN they attempt to navigate to Administration > Procest
- THEN the system MUST deny access
- AND the "Procest" entry MUST NOT appear in the regular user's settings navigation
- AND direct URL access to the admin settings endpoint MUST return HTTP 403

#### Scenario: Group admin access

@e2e exclude Requires creating a group-admin user and verifying settings section visibility; setup complexity exceeds headless Playwright test scope.

- GIVEN a Nextcloud group admin (not full admin)
- WHEN they attempt to access Procest admin settings
- THEN the system MUST deny access (only full Nextcloud admins may configure case types)

#### Scenario: Admin settings page loads with OpenRegister unavailable

@e2e exclude Requires disabling the OpenRegister app; cannot be safely tested in shared CI environment.

- GIVEN the OpenRegister app is not installed or disabled
- WHEN the admin navigates to Procest admin settings
- THEN the page MUST display a clear warning indicating OpenRegister is required
- AND the case type list MUST show an appropriate error state rather than an empty list
- AND all form controls MUST be disabled until OpenRegister is available

### Requirement: In-app Settings page render [MVP]

The in-app Settings page (`Settings.vue`, route `/settings`) SHALL mount and
render its configuration shell on navigation — the "Version Information" and
"Configuration" section headings, the register/schema configuration fields, the
"Case Type Management" section, and the Save / Re-import controls — so an admin
can configure the app from within the SPA. This is a browser-verifiable UI surface
distinct from the Nextcloud admin-settings panel (REQ-ADMIN-001).

#### Scenario: In-app settings page renders configuration sections
- **GIVEN** an authenticated admin user on the Procest app
- **WHEN** they navigate to the in-app Settings page
- **THEN** the page MUST render a "Version Information" section heading
- **AND** a "Configuration" section heading
- **AND** a "Case Type Management" section heading
- **AND** a "Save" control MUST be present

### Requirement: Case Type List View [MVP]

The admin settings MUST display a list of all case types with key metadata, following the `CaseTypeList.vue` component's `CnIndexPage` pattern.

#### Scenario: List all case types

@e2e exclude Requires pre-seeded case types to be visible in the list; data-dependent scenario tested via the empty state scenario instead.

- GIVEN the following case types exist:
  | title                | isDraft | processingDeadline | statusCount | resultTypeCount | validFrom  | validUntil | isDefault |
  |----------------------|---------|-------------------|-------------|-----------------|------------|------------|-----------|
  | Omgevingsvergunning  | false   | P56D              | 4           | 3               | 2026-01-01 | 2027-12-31 | true      |
  | Subsidieaanvraag     | false   | P42D              | 3           | 2               | 2026-01-01 | (none)     | false     |
  | Klacht behandeling   | false   | P28D              | 3           | 2               | 2026-01-01 | (none)     | false     |
  | Bezwaarschrift       | true    | P84D              | 2           | 0               | (not set)  | (none)     | false     |
- WHEN the admin views the case type list
- THEN all 4 case types MUST be displayed
- AND each case type entry MUST show:
  - Title
  - Processing deadline in human-readable form (e.g., "56 days")
  - Count of linked status types (e.g., "4 statuses")
  - Count of linked result types (e.g., "3 result types")
  - Published/Draft badge
  - Validity period (e.g., "Jan 2026 -- Dec 2027" or "Jan 2026 -- (no end)")
- AND the default case type MUST be marked with a star icon or "(default)" label

#### Scenario: Draft types visually distinguished

@e2e exclude Requires a draft case type to exist in the list; data-dependent visual distinction not testable without pre-seeded data.

- GIVEN case type "Bezwaarschrift" has `isDraft = true`
- WHEN the admin views the case type list
- THEN the draft type MUST display a warning badge (e.g., "DRAFT" in amber/yellow)
- AND the draft type SHOULD have a visually different background or border to distinguish it from published types
- AND the validity period MUST show "(not set)" when `validFrom` is not configured

#### Scenario: Click to edit case type

@e2e exclude Requires a published case type in the list to click; data-dependent navigation tested via create flow instead.

- GIVEN the case type list is displayed
- WHEN the admin clicks on "Omgevingsvergunning" or its "Edit" button
- THEN the system MUST navigate to the case type detail/edit view for "Omgevingsvergunning"

#### Scenario: Empty case type list
- GIVEN no case types have been created
- WHEN the admin views the case type list
- THEN the system MUST display an empty state message (e.g., "No case types configured yet")
- AND the "+ Add Case Type" button MUST be prominently displayed
- AND the system SHOULD provide guidance (e.g., "Create your first case type to start managing cases")

### Requirement: Create Case Type [MVP]

The admin MUST be able to create new case types that start in draft status, following the ZGW Catalogi `ZaakType` data model.

#### Scenario: Add a new case type
- GIVEN the admin is on the case type list
- WHEN they click "+ Add Case Type"
- THEN the system MUST present a case type creation form or navigate to a new case type detail view
- AND the new case type MUST have `isDraft = true` by default
- AND the admin MUST be able to fill in at minimum: title, purpose, trigger, subject, processingDeadline, origin, confidentiality, and responsibleUnit (all required fields per ARCHITECTURE.md)

#### Scenario: Created case type appears in list

@e2e exclude Full create-and-verify flow requires form fill + save + re-load; covered by the add-a-new-case-type test verifying the form opens.

- GIVEN the admin fills in the required fields and saves a new case type "Bezwaarschrift"
- WHEN the save completes successfully
- THEN the new case type MUST appear in the case type list with a "DRAFT" badge
- AND the admin MUST be redirected to (or remain on) the detail view to add statuses and other type definitions

#### Scenario: Validation on required fields

@e2e exclude Form validation requires submitting with empty fields; submit-and-assert-error flow deferred; covered by unit tests on caseTypeValidation.js.

- GIVEN the admin tries to save a case type without filling in the title
- WHEN they click Save
- THEN the system MUST display a validation error indicating "Title is required"
- AND the case type MUST NOT be created
- AND all other required fields (purpose, trigger, subject, processingDeadline, origin, confidentiality, responsibleUnit) MUST also show validation errors if empty

#### Scenario: Duplicate case type title warning

@e2e exclude Duplicate title warning requires a case type "Omgevingsvergunning" to already exist; data-dependent scenario not testable without pre-seeded data.

- GIVEN a case type "Omgevingsvergunning" already exists
- WHEN the admin creates a new case type with the same title "Omgevingsvergunning"
- THEN the system SHOULD display a warning that a case type with this title already exists
- AND the system MAY allow the creation (titles are not required to be unique, but the warning helps prevent mistakes)

### Requirement: Case Type Detail/Edit View -- Tabbed Interface [MVP]

The case type detail view MUST use a tabbed interface for organizing the various type definitions, following the `CaseTypeDetail.vue` component pattern.

@e2e exclude Tabbed detail view requires navigating into an existing case type; a case type must be created first; covered by the create + list tests for now; deep-dive tab tests deferred.

#### Scenario: Tab layout
- GIVEN the admin opens the detail view for case type "Omgevingsvergunning"
- THEN the view MUST display the following tabs:
  - **General** (MVP) -- case type core fields
  - **Statuses** (MVP) -- status type management
  - **Results** (V1) -- result type management
  - **Roles** (V1) -- role type management
  - **Properties** (V1) -- property definition management
  - **Documents** (V1) -- document type management
  - **Decisions** (V1) -- decision type management
- AND the "General" tab MUST be selected by default
- AND V1 tabs (Results, Roles, Properties, Documents, Decisions) MAY be hidden or disabled until V1 is implemented

#### Scenario: Save button placement
- GIVEN the admin is editing a case type
- THEN a "Save" button MUST be visible at the top of the page (in the header area)
- AND the Save button MUST persist across tab switches (it is page-level, not tab-level)

#### Scenario: Tab switching preserves unsaved changes
- GIVEN the admin has made unsaved changes on the General tab
- WHEN they switch to the Statuses tab
- THEN the unsaved changes on the General tab MUST be preserved in memory
- AND switching back to the General tab MUST show the unsaved changes
- AND clicking Save on any tab MUST save all pending changes across all tabs

#### Scenario: Back navigation with unsaved changes
- GIVEN the admin is on the case type detail view with unsaved changes
- WHEN they click the breadcrumb link "Procest" to return to the case type list
- THEN the system SHOULD prompt: "You have unsaved changes. Discard?"
- AND confirming MUST navigate back without saving
- AND canceling MUST keep the admin on the detail view

### Requirement: General Tab [MVP]

The General tab MUST allow editing all core case type fields, as implemented in `GeneralTab.vue`.

@e2e exclude General tab field editing requires an existing case type to navigate into; data-dependent CRUD scenarios not testable without pre-seeded case types.

#### Scenario: Display and edit general fields
- GIVEN the admin is on the General tab for "Omgevingsvergunning"
- THEN the following fields MUST be editable:
  | Field               | Value                              | Type          |
  |---------------------|------------------------------------|---------------|
  | Title               | Omgevingsvergunning                | text input    |
  | Description         | Vergunning voor bouwactiviteiten   | textarea      |
  | Purpose             | Beoordelen bouwplannen             | text input    |
  | Trigger             | Aanvraag van burger/bedrijf        | text input    |
  | Subject             | Bouw- en verbouwactiviteiten       | text input    |
  | Processing deadline | 56 (displayed as "P56D")           | number + unit |
  | Service target      | 42 (displayed as "P42D")           | number + unit |
  | Extension allowed   | checked                            | checkbox      |
  | Extension period    | 28 (displayed as "P28D")           | number + unit |
  | Suspension allowed  | checked                            | checkbox      |
  | Origin              | External                           | radio buttons |
  | Confidentiality     | Internal                           | select        |
  | Publication req.    | checked                            | checkbox      |
  | Publication text    | Bouwvergunning verleend...         | text input    |
  | Valid from          | 2026-01-01                         | date picker   |
  | Valid until         | 2027-12-31                         | date picker   |
  | Status              | Published / Draft                  | radio buttons |

#### Scenario: Processing deadline format validation
- GIVEN the admin enters "abc" in the processing deadline field
- WHEN they try to save
- THEN the system MUST display a validation error indicating the deadline must be a valid duration
- AND the system MUST accept ISO 8601 duration format (e.g., "P56D" for 56 days, "P8W" for 8 weeks)
- OR the system MUST provide a simplified input (number + unit selector: days/weeks/months) that converts to ISO 8601

#### Scenario: Extension period required when extension allowed
- GIVEN the admin checks "Extension allowed"
- WHEN they leave the "Extension period" field empty and try to save
- THEN the system MUST display a validation error: "Extension period is required when extension is allowed"

#### Scenario: Extension period hidden when extension not allowed
- GIVEN the admin unchecks "Extension allowed"
- THEN the "Extension period" field MUST be hidden or disabled
- AND any previously set extension period value SHOULD be cleared

#### Scenario: Responsible unit selection
- GIVEN the admin is editing the General tab
- THEN the "Responsible unit" field MUST allow the admin to specify which organizational unit is responsible for cases of this type
- AND this field SHOULD support free text or a dropdown populated from an organizational structure (if available)

### Requirement: Status Type Management [MVP]

The Statuses tab MUST allow managing the ordered list of status types for a case type, as implemented in `StatusesTab.vue`. Status types correspond to ZGW `StatusType` and CMMN Milestone concepts.

@e2e exclude Status type management requires an existing case type to navigate into; data-dependent CRUD and reorder scenarios not testable without pre-seeded case types.

#### Scenario: List status types
- GIVEN case type "Omgevingsvergunning" has the following status types:
  | order | name             | isFinal | notifyInitiator | notificationText                        |
  |-------|------------------|---------|------------------|-----------------------------------------|
  | 1     | Ontvangen        | false   | false            |                                         |
  | 2     | In behandeling   | false   | true             | Uw zaak is in behandeling genomen       |
  | 3     | Besluitvorming   | false   | false            |                                         |
  | 4     | Afgehandeld      | true    | true             | Uw zaak is afgehandeld                  |
- WHEN the admin views the Statuses tab
- THEN all 4 status types MUST be displayed in order
- AND each status type MUST show: order number, name, isFinal checkbox, notifyInitiator toggle
- AND status types with `notifyInitiator = true` MUST show the notification text field below them

#### Scenario: Add a new status type
- GIVEN the admin is on the Statuses tab
- WHEN they click "+ Add" and enter name "Bezwaar"
- THEN a new status type MUST be created with the next sequential order number (5)
- AND the new status type MUST have `isFinal = false` by default
- AND the status type MUST be linked to the current case type

#### Scenario: Reorder status types via drag-and-drop
- GIVEN 4 status types ordered: Ontvangen (1), In behandeling (2), Besluitvorming (3), Afgehandeld (4)
- WHEN the admin drags "Besluitvorming" above "In behandeling"
- THEN the order MUST be updated to: Ontvangen (1), Besluitvorming (2), In behandeling (3), Afgehandeld (4)
- AND all order fields MUST be recalculated as sequential integers starting from 1
- AND each status type row MUST display a drag handle icon (e.g., six dots / hamburger icon)

#### Scenario: Delete status type with active cases
- GIVEN status type "In behandeling" has 5 cases currently in that status
- WHEN the admin tries to delete it
- THEN the system MUST display a warning: "This status is in use by 5 cases. Reassign them before deleting."
- AND the deletion MUST be blocked until no cases reference this status

#### Scenario: Status type notification configuration
- GIVEN status type "In behandeling" on the Statuses tab
- WHEN the admin toggles "Notify initiator" to ON
- THEN a text field for "Notification text" MUST appear below the toggle
- AND the admin MUST be able to enter text such as "Uw zaak is in behandeling genomen"
- AND when the toggle is OFF, the notification text field MUST be hidden

### Requirement: Default Case Type Selection [MVP]

The admin MUST be able to designate one case type as the default, persisted via the `SettingsService` config key `default_case_type`.

@e2e exclude Default case type selection requires published case types to exist; data-dependent interaction not testable without pre-seeded case types.

#### Scenario: Set default case type
- GIVEN case types "Omgevingsvergunning" (default), "Subsidieaanvraag", "Klacht behandeling" exist
- WHEN the admin clicks the default indicator (star/checkbox) on "Subsidieaanvraag"
- THEN "Subsidieaanvraag" MUST become the default case type
- AND "Omgevingsvergunning" MUST lose its default status (only one default at a time)
- AND the star/indicator MUST move to "Subsidieaanvraag"

#### Scenario: Default case type must be published
- GIVEN a draft case type "Bezwaarschrift"
- WHEN the admin tries to set it as default
- THEN the system MUST display an error: "Only published case types can be set as default"
- AND the default MUST NOT change

#### Scenario: No default set
- GIVEN no case type is marked as default
- WHEN a user creates a new case
- THEN the case creation form MUST require explicit case type selection (no pre-selection)

### Requirement: Case Type Publish Action [MVP]

The admin MUST be able to publish a draft case type after validating its completeness. This corresponds to the ZGW Catalogi concept of activating a `ZaakType`.

@e2e exclude Publish action requires a draft case type with/without statuses; data-dependent validation flow not testable without pre-seeded data.

#### Scenario: Publish a complete case type
- GIVEN draft case type "Bezwaarschrift" with:
  - All required general fields filled in
  - At least 1 status type defined
  - `validFrom` date set
- WHEN the admin changes the status from "Draft" to "Published" and saves
- THEN the case type `isDraft` MUST be set to false
- AND the case type MUST now be available for creating new cases
- AND the case type list MUST show "Published" instead of "DRAFT"

#### Scenario: Publish incomplete case type -- no statuses
- GIVEN draft case type "Bezwaarschrift" with no status types defined
- WHEN the admin tries to publish it
- THEN the system MUST display a validation error: "At least one status type is required before publishing"
- AND the case type MUST remain as draft

#### Scenario: Publish incomplete case type -- no validFrom
- GIVEN draft case type "Bezwaarschrift" with status types but no `validFrom` date
- WHEN the admin tries to publish it
- THEN the system MUST display a validation error: "Valid from date is required before publishing"
- AND the case type MUST remain as draft

#### Scenario: Publish incomplete case type -- missing required general fields
- GIVEN draft case type "Bezwaarschrift" with `purpose` field empty
- WHEN the admin tries to publish it
- THEN the system MUST display validation errors for all missing required fields
- AND the case type MUST remain as draft

#### Scenario: Unpublish a published case type
- GIVEN published case type "Klacht behandeling" with no active cases
- WHEN the admin changes the status from "Published" to "Draft"
- THEN the case type `isDraft` MUST be set to true
- AND the case type MUST no longer appear as an option when creating new cases
- AND existing cases of this type MUST NOT be affected

### Requirement: Result Type Management [V1]

The Results tab SHALL allow managing result types with archival rules per case type. Result types correspond to ZGW `ResultaatType` and control case archival behavior per the Archiefwet.

@e2e exclude Results tab is V1; result type CRUD requires a published case type with data; not testable in the current Playwright-testable build.

#### Scenario: List result types
- GIVEN case type "Omgevingsvergunning" has the following result types:
  | name                   | archiveAction | retentionPeriod | retentionDateSource |
  |------------------------|---------------|-----------------|---------------------|
  | Vergunning verleend    | retain        | P20Y            | case_completed      |
  | Vergunning geweigerd   | destroy       | P10Y            | case_completed      |
  | Ingetrokken            | destroy       | P5Y             | case_completed      |
- WHEN the admin views the Results tab
- THEN all 3 result types MUST be displayed
- AND each result type MUST show: name, archive action (retain/destroy), retention period in human-readable form (e.g., "20 years"), and retention date source

#### Scenario: Add a result type
- GIVEN the admin is on the Results tab
- WHEN they click "+ Add" and fill in:
  - Name: "Vergunning verleend"
  - Archive action: "retain"
  - Retention period: "P20Y" (20 years)
  - Retention date source: "case_completed"
- AND click Save
- THEN the result type MUST be created and linked to the current case type
- AND it MUST appear in the result types list

#### Scenario: Edit a result type
- GIVEN result type "Vergunning geweigerd" with retention period P10Y
- WHEN the admin changes the retention period to P15Y
- AND clicks Save
- THEN the retention period MUST be updated to P15Y

#### Scenario: Delete result type in use
- GIVEN result type "Vergunning verleend" is referenced by 3 completed cases
- WHEN the admin tries to delete it
- THEN the system MUST display a warning: "This result type is in use by 3 cases and cannot be deleted"
- AND the deletion MUST be blocked

#### Scenario: Archive action semantics
- GIVEN result type "Vergunning verleend" with archiveAction "retain"
- THEN cases closed with this result MUST be marked for permanent retention in the archive
- AND result type "Ingetrokken" with archiveAction "destroy" MUST cause cases to be scheduled for destruction after the retention period expires
- AND retention date source "case_completed" MUST calculate the destruction date from the case's endDate

### Requirement: Role Type Management [V1]

The Roles tab SHALL allow managing role types with generic role mapping per case type. Role types correspond to ZGW `RolType` with `omschrijvingGeneriek`.

@e2e exclude Roles tab is V1; role type CRUD requires a published case type with data; not testable in the current Playwright-testable build.

#### Scenario: List role types
- GIVEN case type "Omgevingsvergunning" has the following role types:
  | name               | genericRole     |
  |--------------------|-----------------|
  | Aanvrager          | initiator       |
  | Behandelaar        | handler         |
  | Technisch adviseur | advisor         |
  | Beslisser          | decision_maker  |
- WHEN the admin views the Roles tab
- THEN all 4 role types MUST be displayed
- AND each role type MUST show the name and the generic role mapping

#### Scenario: Add a role type
- GIVEN the admin is on the Roles tab
- WHEN they click "+ Add" and enter:
  - Name: "Technisch adviseur"
  - Generic role: "advisor" (selected from dropdown)
- AND click Save
- THEN the role type MUST be created and linked to the current case type

#### Scenario: Generic role dropdown options
- GIVEN the admin is adding or editing a role type
- THEN the "Generic role" field MUST be a dropdown with the following options:
  - initiator, handler, advisor, decision_maker, stakeholder, coordinator, contact, co_initiator
- AND the admin MUST select exactly one generic role per role type

#### Scenario: Delete a role type with active assignments
- GIVEN role type "Technisch adviseur" has 2 active role assignments on cases
- WHEN the admin tries to delete it
- THEN the system MUST display a warning: "This role type is in use by 2 case role assignments"
- AND the system SHOULD either block deletion or offer to remove the assignments first

#### Scenario: Multiple role types with the same generic role
- GIVEN the admin creates role type "Externe adviseur" with genericRole "advisor"
- AND role type "Interne adviseur" already exists with genericRole "advisor"
- THEN the system MUST allow both role types (multiple role types can share the same generic role)
- AND both MUST appear as options when assigning participants to cases of this type

### Requirement: Property Definition Management [V1]

The Properties tab SHALL allow managing custom field definitions per case type. Property definitions correspond to ZGW `Eigenschap`.

@e2e exclude Properties tab is V1; property definition CRUD requires a published case type with data; not testable in the current Playwright-testable build.

#### Scenario: List property definitions
- GIVEN case type "Omgevingsvergunning" has the following property definitions:
  | name              | format | maxLength | requiredAtStatus  |
  |-------------------|--------|-----------|-------------------|
  | Kadastraal nummer | text   | 20        | In behandeling    |
  | Bouwkosten        | number | (none)    | Besluitvorming    |
  | Oppervlakte       | number | (none)    | (optional)        |
  | Bouwlagen         | number | (none)    | (optional)        |
- WHEN the admin views the Properties tab
- THEN all 4 property definitions MUST be displayed
- AND each MUST show: name, format, max length (if set), and the status at which it is required (or "optional")

#### Scenario: Add a property definition
- GIVEN the admin is on the Properties tab
- WHEN they click "+ Add" and fill in:
  - Name: "Kadastraal nummer"
  - Definition: "Het kadastrale perceelnummer"
  - Format: "text" (selected from dropdown: text, number, date, datetime)
  - Max length: 20
  - Required at status: "In behandeling" (selected from the case type's status types)
- AND click Save
- THEN the property definition MUST be created and linked to the current case type

#### Scenario: Required at status dropdown
- GIVEN the admin is adding a property definition
- THEN the "Required at status" field MUST be a dropdown populated with the case type's status types
- AND the dropdown MUST include an "(optional)" or "(not required)" option for properties that are never required

#### Scenario: Delete a property definition
- GIVEN property "Oppervlakte" exists
- WHEN the admin clicks delete and confirms
- THEN the property definition MUST be deleted
- AND any existing case property values for "Oppervlakte" SHOULD be retained on existing cases (orphaned but not lost)

### Requirement: Document Type Management [V1]

The Documents tab SHALL allow managing document type requirements per case type. Document types correspond to ZGW `InformatieObjectType`.

@e2e exclude Documents tab is V1; document type CRUD requires a published case type with data; not testable in the current Playwright-testable build.

#### Scenario: List document types
- GIVEN case type "Omgevingsvergunning" has the following document types:
  | name                   | direction | requiredAtStatus   |
  |------------------------|-----------|---------------------|
  | Bouwtekening           | incoming  | In behandeling      |
  | Constructieberekening  | incoming  | In behandeling      |
  | Situatietekening       | incoming  | In behandeling      |
  | Welstandsadvies        | internal  | Besluitvorming      |
  | Vergunningsbesluit     | outgoing  | Afgehandeld         |
- WHEN the admin views the Documents tab
- THEN all 5 document types MUST be displayed
- AND each MUST show: name, direction (incoming/internal/outgoing), and required-at-status

#### Scenario: Add a document type
- GIVEN the admin is on the Documents tab
- WHEN they click "+ Add" and fill in:
  - Name: "Bouwtekening"
  - Category: "Tekeningen"
  - Direction: "incoming" (selected from dropdown: incoming, internal, outgoing)
  - Required at status: "In behandeling" (from case type's statuses)
- AND click Save
- THEN the document type MUST be created and linked to the current case type

#### Scenario: Direction dropdown options
- GIVEN the admin is adding or editing a document type
- THEN the "Direction" field MUST be a dropdown with options: incoming, internal, outgoing
- AND these MUST map to: documents received from initiator, internal working documents, and documents sent to initiator

#### Scenario: Completeness check for document types
- GIVEN case type "Omgevingsvergunning" has document types with requiredAtStatus "In behandeling"
- WHEN a case of this type reaches status "In behandeling"
- THEN the system SHOULD check whether all required document types have been uploaded
- AND if not, the system SHOULD display a warning on the case detail indicating missing documents

### Requirement: Decision Type Management [V1]

The Decisions tab SHALL allow managing decision type definitions per case type. Decision types correspond to ZGW `BesluitType` and control publication and objection period rules per the Wet open overheid (WOO).

@e2e exclude Decisions tab is V1; decision type CRUD requires a published case type with data; not testable in the current Playwright-testable build.

#### Scenario: List decision types
- GIVEN case type "Omgevingsvergunning" has the following decision types:
  | name                        | publicationRequired | objectionPeriod | category          |
  |-----------------------------|---------------------|-----------------|-------------------|
  | Omgevingsvergunning besluit | true                | P6W             | Vergunningen      |
  | Voorlopige voorziening      | false               | (none)          | Tussentijds       |
- WHEN the admin views the Decisions tab
- THEN all 2 decision types MUST be displayed
- AND each MUST show: name, publication requirement indicator, objection period (if set), and category

#### Scenario: Add a decision type with publication rules
- GIVEN the admin is on the Decisions tab
- WHEN they click "+ Add" and fill in:
  - Name: "Omgevingsvergunning besluit"
  - Category: "Vergunningen"
  - Publication required: checked
  - Publication period: "P6W" (6 weeks)
  - Objection period: "P6W" (6 weeks)
- AND click Save
- THEN the decision type MUST be created and linked to the current case type
- AND decisions of this type MUST enforce publication deadlines when created on cases

#### Scenario: Edit a decision type
- GIVEN decision type "Voorlopige voorziening" exists
- WHEN the admin changes the publicationRequired to true
- AND clicks Save
- THEN future decisions of this type MUST require publication
- AND existing decisions MUST NOT be retroactively affected

### Requirement: Validation Rules [MVP]

The admin settings MUST enforce validation rules on case type configuration, with validation logic implemented in `src/utils/caseTypeValidation.js`.

@e2e exclude Validation rules require editing an existing case type with specific data states; data-dependent flows not testable without pre-seeded case types.

#### Scenario: Processing deadline format validation
- GIVEN the admin enters a processing deadline
- THEN the system MUST validate it as a valid ISO 8601 duration (e.g., "P56D", "P8W", "P2M")
- AND if using a simplified input (number + unit), the system MUST convert to ISO 8601 on save
- AND invalid values (negative numbers, zero, non-numeric input) MUST be rejected with a clear error message

#### Scenario: Valid from must precede valid until
- GIVEN the admin sets validFrom = 2027-01-01 and validUntil = 2026-12-31
- WHEN they try to save
- THEN the system MUST display: "Valid from date must be before valid until date"
- AND the save MUST be blocked

#### Scenario: At least one non-final status required
- GIVEN a case type with only one status type marked as `isFinal = true`
- WHEN the admin tries to save
- THEN the system MUST display a warning: "At least one non-final status is recommended for proper case lifecycle"
- AND the save MAY proceed (warning, not blocking)

#### Scenario: Status type name uniqueness within case type
- GIVEN case type "Omgevingsvergunning" already has a status type "Ontvangen"
- WHEN the admin tries to add another status type named "Ontvangen"
- THEN the system MUST display: "A status type with this name already exists for this case type"
- AND the creation MUST be blocked

### Requirement: Error Scenarios [MVP]

The admin settings MUST handle error conditions gracefully, preserving user data and providing actionable feedback.

@e2e exclude Error scenarios require specific data conditions (active cases, concurrent edits, network failures); not reproducible in stable Playwright test environment.

#### Scenario: Delete published case type with active cases
- GIVEN published case type "Omgevingsvergunning" has 10 active (non-final) cases
- WHEN the admin tries to delete the case type
- THEN the system MUST display a blocking error: "This case type has 10 active cases and cannot be deleted. Close or reassign all cases first."
- AND the case type MUST NOT be deleted

#### Scenario: Delete published case type with only completed cases
- GIVEN published case type "Klacht behandeling" has 5 cases, all with final status
- WHEN the admin tries to delete the case type
- THEN the system MUST display a warning: "This case type has 5 completed cases. Deleting it will make those cases reference a missing type. Proceed?"
- AND upon confirmation, the case type MUST be deleted
- AND the system SHOULD set `isDraft = true` or mark it as archived rather than hard-deleting

#### Scenario: Save fails due to network error
- GIVEN the admin edits a case type and clicks Save
- AND the API request fails due to a network error
- WHEN the error occurs
- THEN the system MUST display an error message: "Failed to save changes. Please try again."
- AND the form data MUST be preserved (not lost)
- AND the admin MUST be able to retry saving without re-entering data

#### Scenario: Concurrent editing conflict
- GIVEN admin "A" and admin "B" both open case type "Omgevingsvergunning" for editing
- AND admin "A" saves changes to the processing deadline
- WHEN admin "B" tries to save their changes
- THEN the system SHOULD detect the conflict (e.g., via version/timestamp comparison)
- AND display a warning: "This case type was modified by another user. Reload to see the latest version."
- OR the system MAY use last-write-wins if conflict detection is not implemented in MVP

## Non-Functional Requirements

- **Performance**: Case type list MUST load within 1 second for up to 50 case types. Case type detail view (including all linked type definitions) MUST load within 2 seconds.
- **Accessibility**: All form fields MUST have associated labels. Drag-and-drop reordering MUST have a keyboard alternative (e.g., up/down arrow buttons). Error messages MUST be associated with their fields via `aria-describedby`. All content MUST meet WCAG AA standards.
- **Localization**: All labels, error messages, validation messages, and placeholder text MUST support English and Dutch localization via `t()` function.
- **Data integrity**: Deleting a case type or sub-entity MUST use soft-delete or referential integrity checks. The system MUST prevent orphaning active cases.
- **Responsiveness**: The admin settings page MUST be usable on desktop viewports (minimum 1024px width). Mobile responsiveness is not required for admin settings.

### Current Implementation Status

**Implemented:**
- Admin panel registration via `OCA\Procest\Settings\AdminSettings` (`lib/Settings/AdminSettings.php`) and `OCA\Procest\Sections\SettingsSection` (`lib/Sections/SettingsSection.php`) -- registers the "Procest" section in Nextcloud admin settings with icon support.
- Admin settings Vue root component (`src/views/settings/AdminRoot.vue`) renders the full admin page with two sections: Case Type Management and ZGW API Mapping.
- Case type list view (`src/views/settings/CaseTypeList.vue`) using `CnIndexPage` -- displays title, isDraft badge (Draft/Published), processing deadline, validity period. Supports set-as-default (star icon, published-only) and delete actions.
- Case type detail/edit view (`src/views/settings/CaseTypeDetail.vue`) with tabbed interface: General and Statuses tabs are implemented. Publish/unpublish buttons with validation errors. Save button in header.
- General tab (`src/views/settings/tabs/GeneralTab.vue`) with fields: title, description, purpose, trigger, subject, processing deadline, service target, extension allowed/period, suspension allowed, origin, confidentiality, publication required/text, valid from/until, draft/published status.
- Statuses tab (`src/views/settings/tabs/StatusesTab.vue`) with ordered list, drag-and-drop reorder, inline editing, add/delete, isFinal checkbox, notifyInitiator toggle with notification text field.
- Case type CRUD via OpenRegister object store (`src/store/modules/object.js` using `createObjectStore` from `@conduction/nextcloud-vue`).
- Default case type selection persisted via `SettingsService` (`lib/Service/SettingsService.php`, config key `default_case_type`).
- Settings controller (`lib/Controller/SettingsController.php`) with index/create/load endpoints.
- Register configuration auto-import from `procest_register.json` (`lib/Service/SettingsService.php::loadConfiguration`).
- Case type admin orchestrator component (`src/views/settings/CaseTypeAdmin.vue`) managing list/detail view switching.
- Duration formatting helpers (`src/utils/durationHelpers.js`).
- Case type validation utilities (`src/utils/caseTypeValidation.js`).
- ZGW API mapping settings (`src/views/settings/ZgwMappingSettings.vue`).

**Not yet implemented:**
- Results tab (V1) -- result type CRUD with archival rules.
- Roles tab (V1) -- role type CRUD with generic role mapping.
- Properties tab (V1) -- property definition CRUD with required-at-status linking.
- Documents tab (V1) -- document type CRUD with direction and required-at-status.
- Decisions tab (V1) -- decision type CRUD with publication rules.
- Publish validation: checking for at least one status type and validFrom date before publishing (partial -- UI has publish errors display but completeness checks may not cover all scenarios).
- Delete case type blocking when active cases exist (no backend enforcement found).
- Concurrent editing conflict detection.
- Keyboard alternative for drag-and-drop reorder.

### Standards & References

- **ZGW Catalogi API (VNG)**: The case type data model maps directly to ZaakType, StatusType, ResultaatType, RolType, EigenschapType, InformatieObjectType, BesluitType from the ZGW Catalogi API specification (VNG-Realisatie/catalogi-api).
- **CMMN 1.1**: Case type modeled after CaseDefinition concept; status types correspond to CMMN Milestone sequences.
- **Schema.org**: Properties use `schema:name`, `schema:description`, `schema:identifier` mappings.
- **ISO 8601**: Duration format for processing deadlines, extension periods, retention periods.
- **WCAG AA**: Spec requires accessible form labels, keyboard alternatives for drag-and-drop, `aria-describedby` for error messages.
- **GEMMA**: Dutch municipal architecture standards for zaakgericht werken.
- **Archiefwet**: Dutch archival law governing retention and destruction of government records. Result type archival rules directly implement selectielijst concepts.
- **Wet open overheid (WOO)**: Decision type publication requirements align with WOO transparency obligations.
- **Competitive reference**: Dimpact ZAC (per-zaaktype parameters, inrichtingscheck), xxllnc Zaken (case type versioning), Flowable (CMMN modeler), ArkCase (pipeline handlers per case type).

### Specificity Assessment

This spec is highly specific and implementation-ready. Requirements are well-structured with concrete scenarios, data tables, and validation rules.

**Strengths:** Detailed Gherkin scenarios covering happy paths and error cases. Clear feature tier separation (MVP vs V1). Explicit field definitions with types. Decisions tab added based on data model.

**Missing/Ambiguous:**
- No API endpoint definitions (REST paths, request/response schemas) -- relies on OpenRegister generic CRUD.
- Publish validation logic not fully specified at the backend level (controller vs service layer responsibility).
- Archival rules for result types reference `retentionDateSource` options but do not define their semantics in detail.
- No specification of how V1 tabs become available (feature flag, config, or automatic based on version).

**Open questions:**
1. Should the admin settings enforce backend validation (server-side) or is frontend validation sufficient for MVP?
2. How should the system handle case type versioning -- can a published case type be edited, or must it be unpublished first?
3. Should delete of status types cascade to status records on existing cases?

### Requirement: SettingsController SHALL expose `index`, `create`, and `load` JSON endpoints for the admin UI runtime

@e2e exclude Backend PHP controller spec; covered by PHPUnit controller tests.

`OCA\Procest\Controller\SettingsController` SHALL expose three action endpoints:
- `index()` — `#[NoAdminRequired]`. SHALL return `{success: true, openRegisters: <bool>, isAdmin: <bool>, config: <SettingsService::getSettings()>}` so the admin Vue app can render itself for both admins and non-admins (read-only view).
- `create()` — admin-only (no `#[NoAdminRequired]`). SHALL take the raw request params, delegate to `SettingsService::updateSettings($data)`, and return `{success: true, config: <updated>}`.
- `load()` — admin-only. SHALL force a fresh re-import of the procest register from `procest_register.json` via `SettingsService::loadConfiguration(force: true)` and return the raw result envelope from that service.

#### Scenario: index reports admin status correctly
- **GIVEN** a logged-in user `alice` who is a member of the `admin` group
- **WHEN** `GET /apps/procest/api/settings` (index) is invoked
- **THEN** the response SHALL contain `"isAdmin": true`
- **AND** SHALL contain `"openRegisters": true` if the `openregister` app is installed

#### Scenario: index works for non-admins
- **GIVEN** a logged-in user `bob` who is NOT in the `admin` group
- **WHEN** `index()` is invoked
- **THEN** the response SHALL contain `"isAdmin": false`
- **AND** SHALL still return the current `config` so the Vue app renders in read-only mode

#### Scenario: load forces a fresh register re-import
- **WHEN** an admin invokes `load()`
- **THEN** the controller SHALL call `SettingsService::loadConfiguration(force: true)`
- **AND** SHALL return the result envelope unchanged (no `success: true` wrapper)

### Requirement: SettingsService SHALL be the single resolver for OpenRegister wiring and SHALL persist all Procest config as IAppConfig key/value pairs

@e2e exclude Backend PHP service spec; covered by PHPUnit service tests.

`OCA\Procest\Service\SettingsService` SHALL provide the central OpenRegister resolver and IAppConfig persistence layer for Procest. The service SHALL expose:
- `isOpenRegisterAvailable(): bool` — returns true iff the `openregister` app is installed AND the `OCA\OpenRegister\Service\ObjectService` class can be resolved from the DI container.
- `getObjectService(): ?object` — returns the resolved `ObjectService`, or `null` (NOT throw) when OpenRegister is unavailable. Per ADR-022, every Procest data-access call SHALL obtain its `ObjectService` through this single resolver.
- `loadConfiguration(bool $force = false): array` — idempotent register import. SHALL read `procest_register.json`, import it via the OpenRegister `ConfigurationService`, auto-configure every schema and register ID returned, and persist them via `setConfigValue()`. When `$force` is true, SHALL re-import unconditionally; otherwise SHALL skip when the persisted version matches the manifest version.
- `getSettings(): array` / `updateSettings(array $data): array` — bulk read/write of the Procest config namespace.
- `getConfigValue(string $key, string $default = ''): string` / `setConfigValue(string $key, string $value): void` — single-key accessors backed by `IAppConfig` under the `procest` app namespace.

#### Scenario: getObjectService returns null when OpenRegister is uninstalled
- **GIVEN** the `openregister` app is not installed
- **WHEN** `getObjectService()` is called
- **THEN** the method SHALL return `null` (NOT throw)
- **AND** `isOpenRegisterAvailable()` SHALL return `false`

#### Scenario: loadConfiguration is idempotent without --force
- **GIVEN** the persisted `procest_register_version` equals the version in `procest_register.json`
- **WHEN** `loadConfiguration()` is called with default `$force = false`
- **THEN** the service SHALL skip the re-import and return the cached configuration envelope

#### Scenario: loadConfiguration(force: true) re-imports unconditionally
- **GIVEN** the persisted version equals the manifest version (no diff)
- **WHEN** `loadConfiguration(force: true)` is called
- **THEN** the service SHALL still call `ConfigurationService::importFromApp(...)`
- **AND** SHALL refresh every persisted schema/register ID from the import result

#### Scenario: config keys survive process restart
- **WHEN** `setConfigValue('register', 'procest')` is called
- **AND** a new request hits the process pool
- **THEN** `getConfigValue('register')` SHALL return `'procest'`

#### Notes
- Both `getObjectService()` and `getConfigurationService()` look up services from the DI container at call time (NOT injection-time) — this is deliberate so Procest can boot even when `openregister` is not yet installed.
- ADR-022 calls out that every register-aware service in Procest MUST go through `SettingsService::getObjectService()` rather than wiring its own ObjectService injection.

### Requirement: SettingsController SHALL expose `index`, `create`, and `load` JSON endpoints for the admin UI runtime

@e2e exclude Backend PHP controller spec; covered by PHPUnit controller tests.

`OCA\Procest\Controller\SettingsController` SHALL expose three action endpoints:
- `index()` — `#[NoAdminRequired]`. SHALL return `{success: true, openRegisters: <bool>, isAdmin: <bool>, config: <SettingsService::getSettings()>}` so the admin Vue app can render itself for both admins and non-admins (read-only view).
- `create()` — admin-only (no `#[NoAdminRequired]`). SHALL take the raw request params, delegate to `SettingsService::updateSettings($data)`, and return `{success: true, config: <updated>}`.
- `load()` — admin-only. SHALL force a fresh re-import of the procest register from `procest_register.json` via `SettingsService::loadConfiguration(force: true)` and return the raw result envelope from that service.

#### Scenario: index reports admin status correctly
- **GIVEN** a logged-in user `alice` who is a member of the `admin` group
- **WHEN** `GET /apps/procest/api/settings` (index) is invoked
- **THEN** the response SHALL contain `"isAdmin": true`
- **AND** SHALL contain `"openRegisters": true` if the `openregister` app is installed

#### Scenario: index works for non-admins
- **GIVEN** a logged-in user `bob` who is NOT in the `admin` group
- **WHEN** `index()` is invoked
- **THEN** the response SHALL contain `"isAdmin": false`
- **AND** SHALL still return the current `config` so the Vue app renders in read-only mode

#### Scenario: load forces a fresh register re-import
- **WHEN** an admin invokes `load()`
- **THEN** the controller SHALL call `SettingsService::loadConfiguration(force: true)`
- **AND** SHALL return the result envelope unchanged (no `success: true` wrapper)

### Requirement: SettingsService SHALL be the single resolver for OpenRegister wiring and SHALL persist all Procest config as IAppConfig key/value pairs

@e2e exclude Backend PHP service spec; covered by PHPUnit service tests.

`OCA\Procest\Service\SettingsService` SHALL provide the central OpenRegister resolver and IAppConfig persistence layer for Procest. The service SHALL expose:
- `isOpenRegisterAvailable(): bool` — returns true iff the `openregister` app is installed AND the `OCA\OpenRegister\Service\ObjectService` class can be resolved from the DI container.
- `getObjectService(): ?object` — returns the resolved `ObjectService`, or `null` (NOT throw) when OpenRegister is unavailable. Per ADR-022, every Procest data-access call SHALL obtain its `ObjectService` through this single resolver.
- `loadConfiguration(bool $force = false): array` — idempotent register import. SHALL read `procest_register.json`, import it via the OpenRegister `ConfigurationService`, auto-configure every schema and register ID returned, and persist them via `setConfigValue()`. When `$force` is true, SHALL re-import unconditionally; otherwise SHALL skip when the persisted version matches the manifest version.
- `getSettings(): array` / `updateSettings(array $data): array` — bulk read/write of the Procest config namespace.
- `getConfigValue(string $key, string $default = ''): string` / `setConfigValue(string $key, string $value): void` — single-key accessors backed by `IAppConfig` under the `procest` app namespace.

#### Scenario: getObjectService returns null when OpenRegister is uninstalled
- **GIVEN** the `openregister` app is not installed
- **WHEN** `getObjectService()` is called
- **THEN** the method SHALL return `null` (NOT throw)
- **AND** `isOpenRegisterAvailable()` SHALL return `false`

#### Scenario: loadConfiguration is idempotent without --force
- **GIVEN** the persisted `procest_register_version` equals the version in `procest_register.json`
- **WHEN** `loadConfiguration()` is called with default `$force = false`
- **THEN** the service SHALL skip the re-import and return the cached configuration envelope

#### Scenario: loadConfiguration(force: true) re-imports unconditionally
- **GIVEN** the persisted version equals the manifest version (no diff)
- **WHEN** `loadConfiguration(force: true)` is called
- **THEN** the service SHALL still call `ConfigurationService::importFromApp(...)`
- **AND** SHALL refresh every persisted schema/register ID from the import result

#### Scenario: config keys survive process restart
- **WHEN** `setConfigValue('register', 'procest')` is called
- **AND** a new request hits the process pool
- **THEN** `getConfigValue('register')` SHALL return `'procest'`

#### Notes
- Both `getObjectService()` and `getConfigurationService()` look up services from the DI container at call time (NOT injection-time) — this is deliberate so Procest can boot even when `openregister` is not yet installed.
- ADR-022 calls out that every register-aware service in Procest MUST go through `SettingsService::getObjectService()` rather than wiring its own ObjectService injection.
