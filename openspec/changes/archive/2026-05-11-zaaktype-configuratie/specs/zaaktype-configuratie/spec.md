---
status: implemented
---
# Zaaktype Configuratie Specification

## Purpose

Zaaktype Configuratie provides a zero-coding admin UI for configuring case types and all their behavioral components: status diagrams, checklists, required documents, deadlines, parafeerroutes, and property definitions. While the Case Types spec (`../case-types/spec.md`) defines the data model and validation rules, this spec covers the configuration UI and workflows that administrators use to set up and maintain case types without developer involvement.

**Tender demand**: 23% of tenders (16/69) explicitly require zero-coding zaaktype configuration. Additionally, 36% of all tenders ask for "zero-coding configuratie" as a general principle. This is a key differentiator -- municipalities want to reduce leveranciersafhankelijkheid.
**Relationship to existing specs**: This spec EXTENDS `case-types` (data model). It does NOT duplicate the data model or validation rules. It adds the admin UI and configuration workflows. Check `case-types` for all entity definitions.
**Standards**: ZGW Catalogi API (ZaakType, StatusType, ResultaatType, InformatieObjectType), CMMN 1.1 (CaseDefinition)
**Feature tier**: V1 (basic CRUD UI, status diagram editor, document type config, property definition config, role type config, result type config), V2 (visual flow designer, import/export, ZTC sync, versioning, test mode)

## ADDED Requirements
---

### Requirement: REQ-ZTC-01 — Case Type CRUD via Admin UI

The system MUST provide an admin interface for creating, editing, and managing case types without code changes.

**Feature tier**: V1

#### Scenario: Create new case type

- **GIVEN** an admin navigating to Procest Admin > Zaaktypen
- **WHEN** the admin clicks "Nieuw zaaktype"
- **THEN** a form MUST be displayed with fields from the case-types spec: title, description, processingDeadline (ISO 8601 duration picker), confidentiality, validFrom, validUntil
- **AND** the case type MUST be created in draft status
- **AND** the admin MUST be warned: "Dit zaaktype is nog een concept. Publiceer het om zaken te kunnen aanmaken."

#### Scenario: Edit existing case type

- **GIVEN** a published case type "Omgevingsvergunning" with 10 active cases
- **WHEN** the admin edits the case type
- **THEN** the system MUST warn: "Er zijn 10 actieve zaken van dit type. Wijzigingen gelden alleen voor nieuwe zaken."
- **AND** the admin MAY choose to create a new version instead of editing in-place

#### Scenario: Publish a draft case type

- **GIVEN** a draft case type "Bezwaarschrift" with at least one status type configured
- **WHEN** the admin clicks "Publiceren"
- **THEN** the system MUST validate that the case type has: at least one status type, at least one status marked as `isFinal`, a valid `processingDeadline`
- **AND** if validation passes, the case type `isDraft` MUST be set to `false`
- **AND** the case type MUST become available for case creation

#### Scenario: Publish validation fails

- **GIVEN** a draft case type "Nieuwe Procedure" with no status types configured
- **WHEN** the admin clicks "Publiceren"
- **THEN** the system MUST reject the publish with error: "Zaaktype kan niet gepubliceerd worden: geen statustypen geconfigureerd"
- **AND** the case type MUST remain in draft status

#### Scenario: Delete draft case type

- **GIVEN** a draft case type "Test Zaaktype" with no active cases
- **WHEN** the admin clicks "Verwijderen"
- **THEN** the system MUST display a confirmation dialog
- **AND** upon confirmation, the case type and all linked status types, document types, property definitions, and result types MUST be deleted

#### Scenario: Delete published case type with active cases blocked

- **GIVEN** a published case type "Omgevingsvergunning" with 10 active cases
- **WHEN** the admin attempts to delete it
- **THEN** the system MUST reject: "Kan niet verwijderd worden: er zijn 10 actieve zaken van dit type"
- **AND** the case type MUST NOT be deleted

#### Scenario: Set case type as default

- **GIVEN** multiple published case types
- **WHEN** the admin marks "Omgevingsvergunning" as the default
- **THEN** the case creation form MUST pre-select this case type
- **AND** only one case type MAY be the default at a time

---

### Requirement: REQ-ZTC-02 — Status Diagram Editor

The system MUST provide a visual editor for configuring the status lifecycle of a case type.

**Feature tier**: V1

#### Scenario: Add and order statuses

- **GIVEN** a new case type "Bezwaarschrift"
- **WHEN** the admin opens the status configuration tab
- **THEN** the admin MUST be able to add status types: "Ontvangen", "Vooronderzoek", "Hoorzitting", "Beslissing op bezwaar"
- **AND** statuses MUST be orderable via drag-and-drop or order number input
- **AND** the admin MUST mark "Beslissing op bezwaar" as `isFinal = true`
- **AND** a visual diagram MUST show the status flow as a horizontal timeline

#### Scenario: Configure status properties

- **GIVEN** status type "Hoorzitting" on case type "Bezwaarschrift"
- **WHEN** the admin edits the status
- **THEN** the admin MUST be able to configure: description, `notifyInitiator` (yes/no), `notificationText`, required properties at this status, required documents at this status

#### Scenario: Prevent deleting status with active cases

- **GIVEN** a status type "In behandeling" that is the current status of 5 cases
- **WHEN** the admin attempts to delete this status
- **THEN** the system MUST reject: "Kan niet verwijderd worden: 5 zaken hebben deze status"

#### Scenario: Reorder statuses

- **GIVEN** a case type with statuses in order: "Ontvangen" (1), "In behandeling" (2), "Besluitvorming" (3), "Afgehandeld" (4)
- **WHEN** the admin drags "Besluitvorming" before "In behandeling"
- **THEN** the order MUST update to: "Ontvangen" (1), "Besluitvorming" (2), "In behandeling" (3), "Afgehandeld" (4)
- **AND** the visual timeline MUST reflect the new order immediately

#### Scenario: Status diagram color coding

- **GIVEN** the visual status diagram
- **THEN** each status type MUST display with a color indicator
- **AND** the admin SHOULD be able to assign a color to each status (or use system defaults)
- **AND** the colors MUST be used consistently in the case list and case detail views

---

### Requirement: REQ-ZTC-03 — Document Type Configuration

The system MUST provide a UI for configuring which document types are required per case type.

**Feature tier**: V1

#### Scenario: Add required document types

- **GIVEN** case type "Omgevingsvergunning"
- **WHEN** the admin opens the document configuration tab
- **THEN** the admin MUST be able to add document types with: name, direction (incoming/outgoing/internal), requiredAtStatus (dropdown of configured statuses), description
- **AND** example: "Bouwtekening" (incoming, required at "In behandeling")

#### Scenario: Edit document type

- **GIVEN** a document type "Bouwtekening" configured as incoming, required at "In behandeling"
- **WHEN** the admin changes requiredAtStatus to "Ontvangen"
- **THEN** the system MUST update the document type configuration
- **AND** the change MUST affect new cases only (existing cases retain their current checklist state)

#### Scenario: Delete document type

- **GIVEN** a document type "Welstandsadvies" on case type "Omgevingsvergunning"
- **WHEN** the admin deletes it
- **THEN** the document type MUST be removed from the case type configuration
- **AND** existing cases MUST NOT lose already-uploaded documents of this type

#### Scenario: Document type direction validation

- **GIVEN** the admin adding a new document type
- **THEN** the direction dropdown MUST only show: "incoming" (van aanvrager), "outgoing" (naar aanvrager), "internal" (intern)
- **AND** each direction MUST have a localized label in Dutch and English

---

### Requirement: REQ-ZTC-04 — Property Definition Configuration

The system MUST provide a UI for configuring custom property definitions (case-specific data fields) per case type.

**Feature tier**: V1

#### Scenario: Add custom properties

- **GIVEN** case type "Omgevingsvergunning"
- **WHEN** the admin opens the property definitions tab
- **THEN** the admin MUST be able to add properties with: name, type (text/number/date/boolean/enum/reference), required (yes/no), requiredAtStatus, description, validation rules
- **AND** example: "Bouwkosten" (number, required at "Ontvangen", min=0)

#### Scenario: Enum property with predefined values

- **GIVEN** a property "Type bouwwerk" on case type "Omgevingsvergunning"
- **WHEN** the admin sets type to "enum"
- **THEN** the admin MUST be able to define allowed values: ["Woning", "Bedrijfspand", "Bijgebouw", "Overig"]
- **AND** the case form MUST render a dropdown with these options

#### Scenario: Property with validation rules

- **GIVEN** a property "Bouwkosten" of type "number"
- **WHEN** the admin configures validation: min=0, max=10000000
- **THEN** the case form MUST enforce these limits
- **AND** the admin UI MUST display the configured validation rules clearly

#### Scenario: Required-at-status property

- **GIVEN** a property "Kadastraal nummer" with requiredAtStatus = "In behandeling"
- **WHEN** the configuration is saved
- **THEN** cases of this type MUST NOT be able to advance to "In behandeling" without this property filled
- **AND** the case detail MUST visually indicate which properties are required at the next status

#### Scenario: Reference property to external register

- **GIVEN** a property "BAG adres" of type "reference"
- **WHEN** the admin configures it to reference the BAG register's nummeraanduiding schema
- **THEN** the case form MUST show a search field for looking up BAG addresses
- **AND** the selected address MUST be stored as a reference to the BAG object

---

### Requirement: REQ-ZTC-05 — Role Type Configuration

The system MUST provide a UI for configuring which role types are available per case type.

**Feature tier**: V1

#### Scenario: Add role types

- **GIVEN** case type "Omgevingsvergunning"
- **WHEN** the admin opens the role types tab
- **THEN** the admin MUST be able to add role types with: name, description, maxCount (e.g., 1 for handler, unlimited for advisors)
- **AND** example: "Behandelaar" (max 1), "Aanvrager" (max 1), "Technisch adviseur" (unlimited)

#### Scenario: Role type with person/organization restriction

- **GIVEN** a role type "Aanvrager"
- **WHEN** the admin configures it
- **THEN** the admin MUST be able to set whether the role can be filled by: person only, organization only, or both
- **AND** this restriction MUST be enforced when adding participants to a case

#### Scenario: Default role types pre-populated

- **GIVEN** a new case type is created
- **THEN** the system SHOULD pre-populate with default role types: "Behandelaar" and "Aanvrager"
- **AND** the admin MAY add, edit, or remove these defaults

---

### Requirement: REQ-ZTC-06 — Result Type Configuration

The system MUST provide a UI for configuring which result types are available per case type, including archival rules.

**Feature tier**: V1

#### Scenario: Add result types with archival rules

- **GIVEN** case type "Omgevingsvergunning"
- **WHEN** the admin opens the result types tab
- **THEN** the admin MUST be able to add result types with: name, description, archiveAction (retain/destroy), retentionPeriod (ISO 8601 duration), retentionDateSource (case_completed/case_started)
- **AND** example: "Vergunning verleend" (retain, P20Y, case_completed)

#### Scenario: Result type selectielijst alignment

- **GIVEN** the Dutch Selectielijst for archive management
- **WHEN** the admin configures a result type
- **THEN** the system SHOULD provide a selectielijst dropdown for common archival categories
- **AND** the archiveAction and retentionPeriod SHOULD auto-fill based on the selectielijst selection

#### Scenario: At least one result type required for publish

- **GIVEN** a case type with no result types configured
- **WHEN** the admin attempts to publish the case type
- **THEN** the system MUST warn: "Geen resultaattypen geconfigureerd. Zaken kunnen niet worden afgesloten zonder resultaat."
- **AND** the admin MAY proceed (result is optional at some case types) or add result types first

---

### Requirement: REQ-ZTC-07 — Parafeerroute Configuration

The system MUST provide a UI for configuring B&W parafeerroutes per case type and voorstel type.

**Feature tier**: V2

#### Scenario: Configure parafeerroute

- **GIVEN** case type "Omgevingsvergunning"
- **WHEN** the admin opens the parafeerroute configuration
- **THEN** the admin MUST be able to define ordered steps: step name, actor type (role/person/group), action (advise/parafeer/accord), parallel (yes/no)
- **AND** the route MUST be previewable as a visual flow diagram

#### Scenario: Parafeerroute with parallel steps

- **GIVEN** a parafeerroute with 4 steps
- **WHEN** the admin marks steps 2 and 3 as parallel
- **THEN** the visual diagram MUST show steps 2 and 3 side by side
- **AND** both MUST be completed before step 4 can start

#### Scenario: Parafeerroute template reuse

- **GIVEN** a parafeerroute configured on "Omgevingsvergunning"
- **WHEN** the admin creates a new case type "Sloopvergunning"
- **THEN** the admin SHOULD be able to copy the parafeerroute from "Omgevingsvergunning"
- **AND** the copied route MUST be independently editable

---

### Requirement: REQ-ZTC-08 — Import and Export Configuration

The system MUST support importing and exporting case type configurations for sharing between environments or municipalities.

**Feature tier**: V2

#### Scenario: Export case type as JSON

- **GIVEN** a fully configured case type "Omgevingsvergunning" with 4 statuses, 5 document types, 8 properties, 4 role types, 3 result types, and a parafeerroute
- **WHEN** the admin clicks "Exporteren"
- **THEN** the system MUST generate a JSON file containing the complete configuration
- **AND** the export MUST include all related entities (statuses, document types, properties, role types, result types, parafeerroute)
- **AND** the export format MUST follow the OpenRegister JSON format with `@self` references

#### Scenario: Import case type from JSON

- **GIVEN** a JSON export from another Procest instance
- **WHEN** the admin clicks "Importeren" and uploads the file
- **THEN** the system MUST create the case type and all related entities in draft status
- **AND** the admin MUST review and publish before the type becomes active
- **AND** conflicts (e.g., duplicate names) MUST be flagged for resolution

#### Scenario: ZTC catalog sync

- **GIVEN** a ZGW Catalogi API endpoint with zaaktypen
- **WHEN** the admin configures sync with the external catalog
- **THEN** the system MUST import zaaktypen, statustypen, resultaattypen, and informatieobjecttypen
- **AND** the imported types MUST be mapped to Procest's internal model

#### Scenario: Export preserves relationships

- **GIVEN** a case type with status type "In behandeling" referenced by a property definition (requiredAtStatus)
- **WHEN** the case type is exported
- **THEN** the export MUST preserve the relationship between property definition and status type
- **AND** upon import, the relationship MUST be correctly re-established

---

### Requirement: REQ-ZTC-09 — Version Management

The system SHALL support versioning of case type configurations.

**Feature tier**: V2

#### Scenario: Create new version

- **GIVEN** a published case type "Omgevingsvergunning v1" with 50 active cases
- **AND** a new regulation requires changes to the status flow
- **WHEN** the admin clicks "Nieuwe versie aanmaken"
- **THEN** the system MUST clone the current configuration as "Omgevingsvergunning v2" in draft
- **AND** existing cases MUST remain linked to v1
- **AND** new cases MUST use v2 once published
- **AND** both versions MUST be visible in the admin overview

#### Scenario: Version comparison

- **GIVEN** two versions of "Omgevingsvergunning" (v1 and v2)
- **WHEN** the admin views the version history
- **THEN** the system SHOULD show a diff of changes between versions
- **AND** the diff MUST highlight: added statuses, removed statuses, changed properties, changed deadlines

#### Scenario: Retire old version

- **GIVEN** "Omgevingsvergunning v1" with 3 remaining active cases (47 completed)
- **WHEN** the admin retires v1
- **THEN** the 3 active cases MUST remain on v1 until completion
- **AND** no new cases can be created with v1
- **AND** the admin overview MUST mark v1 as "retired"

---

### Requirement: REQ-ZTC-10 — Test Mode

The system SHALL support testing a case type configuration before publishing.

**Feature tier**: V2

#### Scenario: Test case type in sandbox

- **GIVEN** a draft case type "Nieuwe Subsidie"
- **WHEN** the admin clicks "Testen"
- **THEN** the system MUST allow creating a test case that does not appear in production views
- **AND** the admin MUST be able to walk through the full lifecycle: status changes, document uploads, property filling
- **AND** the test case MUST be automatically cleaned up after testing

#### Scenario: Test mode visual indicator

- **GIVEN** a test case created from a draft case type
- **WHEN** the admin views the test case
- **THEN** the case MUST display a prominent "TEST" banner
- **AND** the case MUST NOT appear in dashboards, reports, or the main case list

#### Scenario: Test mode limitations

- **GIVEN** a test case
- **THEN** the system MUST NOT send real notifications (initiator notifications, assignment notifications)
- **AND** the test case MUST NOT be counted in KPIs or SLA metrics
- **AND** the test case MUST be deletable without audit trail requirements

---

### Requirement: REQ-ZTC-11 — Admin Settings Navigation

The admin UI MUST provide clear navigation between case type configuration areas.

**Feature tier**: V1

#### Scenario: Tab-based configuration

- **GIVEN** an admin editing case type "Omgevingsvergunning"
- **THEN** the configuration screen MUST show tabs: General, Statuses, Document Types, Properties, Role Types, Result Types
- **AND** each tab MUST show the count of configured items (e.g., "Statuses (4)")
- **AND** switching tabs MUST preserve unsaved changes or prompt to save

#### Scenario: Case type list overview

- **GIVEN** 5 case types: 3 published, 2 draft
- **WHEN** the admin navigates to Procest Admin > Zaaktypen
- **THEN** the list MUST show: title, status (published/draft badge), processing deadline, validity period, active case count, and actions (edit, delete, set default)
- **AND** published case types MUST be visually distinct from drafts

#### Scenario: Inline validation feedback

- **GIVEN** the admin is configuring a case type
- **WHEN** the admin leaves a required field empty (e.g., title)
- **THEN** the system MUST show inline validation errors immediately
- **AND** the "Save" button MUST be disabled while validation errors exist

---

### Requirement: REQ-ZTC-12 — Duration Picker for Processing Deadline

The system MUST provide a user-friendly duration picker for the ISO 8601 processing deadline.

**Feature tier**: V1

#### Scenario: Duration picker input

- **GIVEN** the admin is setting `processingDeadline` on a case type
- **THEN** the system MUST provide a picker with fields for: weeks and/or days
- **AND** the picker MUST convert the input to ISO 8601 duration format (e.g., 8 weeks = "P56D")
- **AND** the picker MUST display common presets: "6 weken (P42D)", "8 weken (P56D)", "13 weken (P91D)", "26 weken (P182D)"

#### Scenario: Custom duration entry

- **GIVEN** the admin wants a non-standard deadline of 35 days
- **WHEN** the admin enters "35 days" in the picker
- **THEN** the system MUST store "P35D" as the processingDeadline
- **AND** the display MUST show "35 dagen (5 weken)"

#### Scenario: Duration picker for extension period

- **GIVEN** a case type with `extensionAllowed = true`
- **THEN** the admin MUST be able to set `extensionPeriod` using the same duration picker
- **AND** common presets for extensions SHOULD be: "2 weken (P14D)", "4 weken (P28D)", "6 weken (P42D)"

## Dependencies

- **Case Types spec** (`../case-types/spec.md`): Defines the data model this UI configures.
- **Case Management spec** (`../case-management/spec.md`): Cases use the configured case types.
- **B&W Parafering spec** (`../bw-parafering/spec.md`): Parafeerroutes are configured per case type.
- **Admin Settings spec** (`../admin-settings/spec.md`): Admin UI framework and navigation.
- **OpenRegister**: All configuration stored as OpenRegister objects.

---

### Current Implementation Status

**V1 partially implemented.** Basic case type CRUD and status configuration exist. Advanced features (document type config, property definition config, role type config, result type config, parafeerroute, import/export, versioning, test mode) are not implemented.

**Implemented (with file paths):**
- **Case type CRUD via admin UI (REQ-ZTC-01)**:
  - `src/views/settings/CaseTypeList.vue` -- lists all case types with title, draft/published badge, processing deadline, validity period, and actions (set default, edit, delete).
  - `src/views/settings/CaseTypeDetail.vue` -- detail/edit view for a single case type with tabs.
  - `src/views/settings/CaseTypeAdmin.vue` -- admin wrapper component.
  - `src/views/settings/AdminRoot.vue` -- admin root with case type list and detail.
  - `src/views/settings/tabs/GeneralTab.vue` -- general properties tab for case type editing (title, description, processingDeadline, confidentiality, etc.).
- **Status diagram editor (REQ-ZTC-02 partial)**:
  - `src/views/settings/tabs/StatusesTab.vue` -- status type configuration within a case type. Supports adding, ordering, and editing statuses. Includes `isFinal` marking.
  - `src/views/cases/components/StatusTimeline.vue` -- visual timeline showing status progression on case detail.
- **Case type validation**: `src/utils/caseTypeValidation.js` -- client-side validation for case type fields.
- **Navigation**: `src/navigation/MainMenu.vue` -- "Case Types" menu item in the settings footer, linked to `/case-types` route.
- **Router**: `src/router/index.js` -- route `{ path: '/case-types', name: 'CaseTypes', component: AdminRoot }`.
- **Schema definitions**: All 7 configuration schemas defined in `lib/Settings/procest_register.json`: `caseType`, `statusType`, `resultType`, `roleType`, `propertyDefinition`, `documentType`, `decisionType`.
- **ZGW catalog API**: `lib/Controller/ZtcController.php` -- full ZGW Catalogi API with CRUD for zaaktypen, statustypen, resultaattypen, informatieobjecttypen. Includes publish endpoints (`POST .../zaaktypen/{uuid}/publish`).
- **ZGW catalog rules**: `lib/Service/ZgwZtcRulesService.php` -- validation rules for zaaktype creation and modification.

**Not yet implemented:**
- **REQ-ZTC-03: Document type configuration (V1)**: No admin UI for configuring document types per case type. The `documentType` schema exists but no management UI.
- **REQ-ZTC-04: Property definition configuration (V1)**: No admin UI for configuring custom properties per case type. The `propertyDefinition` schema exists but no management UI. No enum value editor.
- **REQ-ZTC-05: Role type configuration (V1)**: No admin UI for configuring role types per case type. The `roleType` schema exists but no management UI.
- **REQ-ZTC-06: Result type configuration (V1)**: No admin UI for configuring result types per case type. The `resultType` schema exists but no management UI.
- **REQ-ZTC-07: Parafeerroute configuration (V2)**: No parafeerroute configuration UI. No visual flow diagram for approval routes.
- **REQ-ZTC-08: Import/export configuration (V2)**: No JSON export/import for case type configurations. No ZTC catalog sync.
- **REQ-ZTC-09: Version management (V2)**: No versioning of case type configurations. No clone/new version functionality.
- **REQ-ZTC-10: Test mode (V2)**: No sandbox/test mode for case types.
- **REQ-ZTC-12: Duration picker (V1)**: No duration picker component; processingDeadline is entered as raw ISO 8601 string.
- **Status drag-and-drop ordering**: Status ordering may be manual (number input) rather than drag-and-drop.
- **Warning on editing published case type**: No "10 active cases" warning when editing a published case type.
- **Publish validation**: No pre-publish validation checking for status types, final status, and processing deadline.

### Standards & References

- **ZGW Catalogi API (VNG Realisatie)**: Full ZGW-compliant catalog API via `ZtcController.php`. Supports ZaakType, StatusType, ResultaatType, RolType, InformatieObjectType, BesluitType, Eigenschap. Includes publish endpoints.
- **CMMN 1.1**: CaseDefinition patterns for case type configuration with status lifecycle.
- **GEMMA**: Zaaktype catalogus is a standard component in the GEMMA reference architecture.
- **Common Ground**: Configuration data stored as OpenRegister objects in the information layer.
- **Selectielijst**: Dutch archival selection list determining retention periods per case type category.
- **Archiefwet**: Archival rules linked to result types (retain/destroy with retention periods).
- **Competitor reference**: Dimpact ZAC provides a zaaktype admin interface integrated with Flowable CMMN modeling. CaseFabric offers a visual case type designer with drag-and-drop status flows. Flowable Design provides low-code case definition with visual CMMN editor.
