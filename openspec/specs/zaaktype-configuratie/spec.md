# Zaaktype Configuratie Specification

## Purpose

Zaaktype Configuratie provides a zero-coding admin UI for configuring case types and all their behavioral components: status diagrams, checklists, required documents, deadlines, parafeerroutes, and property definitions. While the Case Types spec (`../case-types/spec.md`) defines the data model and validation rules, this spec covers the configuration UI and workflows that administrators use to set up and maintain case types without developer involvement.

**Tender demand**: 23% of tenders (16/69) explicitly require zero-coding zaaktype configuration. Additionally, 36% of all tenders ask for "zero-coding configuratie" as a general principle. This is a key differentiator -- municipalities want to reduce leveranciersafhankelijkheid.
**Relationship to existing specs**: This spec EXTENDS `case-types` (data model). It does NOT duplicate the data model or validation rules. It adds the admin UI and configuration workflows. Check `case-types` for all entity definitions.
**Standards**: ZGW Catalogi API (ZaakType, StatusType, ResultaatType, InformatieObjectType), CMMN 1.1 (CaseDefinition)
**Feature tier**: V1 (basic CRUD UI, status diagram editor, document type config), V2 (visual flow designer, import/export, ZTC sync, versioning)

## Requirements

---

### REQ-ZTC-01: Case Type CRUD via Admin UI

**Feature tier**: V1

The system MUST provide an admin interface for creating, editing, and managing case types without code changes.

#### Scenario ZTC-01a: Create new case type

- GIVEN an admin navigating to Procest Admin > Zaaktypen
- WHEN the admin clicks "Nieuw zaaktype"
- THEN a form MUST be displayed with fields from the case-types spec: title, description, processingDeadline (ISO 8601 duration picker), confidentiality, validFrom, validUntil
- AND the case type MUST be created in draft status
- AND the admin MUST be warned: "Dit zaaktype is nog een concept. Publiceer het om zaken te kunnen aanmaken."

#### Scenario ZTC-01b: Edit existing case type

- GIVEN a published case type "Omgevingsvergunning" with 10 active cases
- WHEN the admin edits the case type
- THEN the system MUST warn: "Er zijn 10 actieve zaken van dit type. Wijzigingen gelden alleen voor nieuwe zaken."
- AND the admin MAY choose to create a new version instead of editing in-place

---

### REQ-ZTC-02: Status Diagram Editor

**Feature tier**: V1

The system MUST provide a visual editor for configuring the status lifecycle of a case type.

#### Scenario ZTC-02a: Add and order statuses

- GIVEN a new case type "Bezwaarschrift"
- WHEN the admin opens the status configuration tab
- THEN the admin MUST be able to add status types: "Ontvangen", "Vooronderzoek", "Hoorzitting", "Beslissing op bezwaar"
- AND statuses MUST be orderable via drag-and-drop
- AND the admin MUST mark "Beslissing op bezwaar" as `isFinal = true`
- AND a visual diagram MUST show the status flow as a horizontal timeline

#### Scenario ZTC-02b: Configure status properties

- GIVEN status type "Hoorzitting" on case type "Bezwaarschrift"
- WHEN the admin edits the status
- THEN the admin MUST be able to configure: description, `notifyInitiator` (yes/no), `notificationText`, required properties at this status, required documents at this status

---

### REQ-ZTC-03: Document Type Configuration

**Feature tier**: V1

The system MUST provide a UI for configuring which document types are required per case type.

#### Scenario ZTC-03a: Add required document types

- GIVEN case type "Omgevingsvergunning"
- WHEN the admin opens the document configuration tab
- THEN the admin MUST be able to add document types with: name, direction (incoming/outgoing/internal), requiredAtStatus (dropdown of configured statuses), description
- AND example: "Bouwtekening" (incoming, required at "In behandeling")

---

### REQ-ZTC-04: Property Definition Configuration

**Feature tier**: V1

The system MUST provide a UI for configuring custom property definitions (case-specific data fields) per case type.

#### Scenario ZTC-04a: Add custom properties

- GIVEN case type "Omgevingsvergunning"
- WHEN the admin opens the property definitions tab
- THEN the admin MUST be able to add properties with: name, type (text/number/date/boolean/enum/reference), required (yes/no), requiredAtStatus, description, validation rules
- AND example: "Bouwkosten" (number, required at "Ontvangen", min=0)

#### Scenario ZTC-04b: Enum property with predefined values

- GIVEN a property "Type bouwwerk" on case type "Omgevingsvergunning"
- WHEN the admin sets type to "enum"
- THEN the admin MUST be able to define allowed values: ["Woning", "Bedrijfspand", "Bijgebouw", "Overig"]
- AND the case form MUST render a dropdown with these options

---

### REQ-ZTC-05: Parafeerroute Configuration

**Feature tier**: V2

The system MUST provide a UI for configuring B&W parafeerroutes per case type and voorstel type.

#### Scenario ZTC-05a: Configure parafeerroute

- GIVEN case type "Omgevingsvergunning"
- WHEN the admin opens the parafeerroute configuration
- THEN the admin MUST be able to define ordered steps: step name, actor type (role/person/group), action (advise/parafeer/accord), parallel (yes/no)
- AND the route MUST be previewable as a visual flow diagram

---

### REQ-ZTC-06: Import and Export Configuration

**Feature tier**: V2

The system MUST support importing and exporting case type configurations for sharing between environments or municipalities.

#### Scenario ZTC-06a: Export case type as JSON

- GIVEN a fully configured case type "Omgevingsvergunning" with 4 statuses, 5 document types, 8 properties, and a parafeerroute
- WHEN the admin clicks "Exporteren"
- THEN the system MUST generate a JSON file containing the complete configuration
- AND the export MUST include all related entities (statuses, document types, properties, parafeerroute)

#### Scenario ZTC-06b: Import case type from JSON

- GIVEN a JSON export from another Procest instance
- WHEN the admin clicks "Importeren" and uploads the file
- THEN the system MUST create the case type and all related entities in draft status
- AND the admin MUST review and publish before the type becomes active
- AND conflicts (e.g., duplicate names) MUST be flagged for resolution

#### Scenario ZTC-06c: ZTC catalog sync

- GIVEN a ZGW Catalogi API endpoint with zaaktypen
- WHEN the admin configures sync with the external catalog
- THEN the system MUST import zaaktypen, statustypen, resultaattypen, and informatieobjecttypen
- AND the imported types MUST be mapped to Procest's internal model

---

### REQ-ZTC-07: Version Management

**Feature tier**: V2

The system SHOULD support versioning of case type configurations.

#### Scenario ZTC-07a: Create new version

- GIVEN a published case type "Omgevingsvergunning v1" with 50 active cases
- AND a new regulation requires changes to the status flow
- WHEN the admin clicks "Nieuwe versie aanmaken"
- THEN the system MUST clone the current configuration as "Omgevingsvergunning v2" in draft
- AND existing cases MUST remain linked to v1
- AND new cases MUST use v2 once published
- AND both versions MUST be visible in the admin overview

---

### REQ-ZTC-08: Test Mode

**Feature tier**: V2

The system SHOULD support testing a case type configuration before publishing.

#### Scenario ZTC-08a: Test case type in sandbox

- GIVEN a draft case type "Nieuwe Subsidie"
- WHEN the admin clicks "Testen"
- THEN the system MUST allow creating a test case that does not appear in production views
- AND the admin MUST be able to walk through the full lifecycle: status changes, document uploads, property filling
- AND the test case MUST be automatically cleaned up after testing

## Dependencies

- **Case Types spec** (`../case-types/spec.md`): Defines the data model this UI configures.
- **Case Management spec** (`../case-management/spec.md`): Cases use the configured case types.
- **B&W Parafering spec** (`../bw-parafering/spec.md`): Parafeerroutes are configured per case type.
- **Admin Settings spec** (`../admin-settings/spec.md`): Admin UI framework and navigation.
- **OpenRegister**: All configuration stored as OpenRegister objects.
