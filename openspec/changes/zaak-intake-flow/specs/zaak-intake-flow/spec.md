---
status: implemented
---
# Zaak Intake Flow Specification

## Purpose

The zaak intake flow governs what happens after a case is initiated -- whether from Open Formulieren, DSO/Omgevingsloket, manual entry, or API call. It handles automatic zaaktype assignment, status initialization, initial task creation, notification to the assigned behandelaar, and linking of the initiator. This is the bridge between external input and the internal case lifecycle.

**Tender demand**: 61% of tenders (42/69) require formulieren/intake capabilities. Automatic case creation from external submissions is a baseline expectation.
**Standards**: ZGW Zaken API (`zaak-create`), StUF-ZKN (`creeerZaak_Lk01`), CMMN 1.1 (CasePlanModel instantiation)
**Feature tier**: MVP (manual + API intake, zaaktype assignment, status init, behandelaar notification), V1 (Open Formulieren integration, DSO intake, duplicate detection, batch intake, e-mail intake)

## Intake Channels

| Channel | Protocol | Description | Tier |
|---------|----------|-------------|------|
| Manual entry | Procest UI | Behandelaar creates case via "New Case" form | MVP |
| ZGW API | REST (`POST /zaken/api/v1/zaken`) | External system creates case via ZGW-compliant endpoint | MVP |
| Open Formulieren | Webhook / ZGW API | Citizen submits e-form with DigiD; form engine calls zaak-create | V1 |
| DSO/Omgevingsloket | StUF-LVO / REST | Omgevingswet application forwarded from DSO | V1 |
| E-mail | IMAP trigger | Incoming e-mail parsed and converted to case (via n8n) | V1 |
| Bulk import | CSV/JSON upload | Batch case creation for migration or seasonal intake | V1 |

## Requirements

---

### REQ-INTAKE-01: Manual Case Creation

The system MUST support creating cases via the Procest UI with a guided creation form.

**Feature tier**: MVP


#### Scenario INTAKE-01a: Create case via dialog

- GIVEN a user with case management access
- WHEN the user clicks "+ New Case" on the dashboard or case list
- THEN the system MUST display the `CaseCreateDialog` with fields: case type (dropdown), title (text), description (text area)
- AND the case type dropdown MUST only show published, currently valid case types
- AND the default case type (if configured) MUST be pre-selected

#### Scenario INTAKE-01b: Case type selection shows metadata

- GIVEN the case type dropdown is open
- WHEN the user hovers over or selects "Omgevingsvergunning"
- THEN the system SHOULD display: processing deadline ("56 days"), description, and number of required document types
- AND this helps the user select the correct case type

#### Scenario INTAKE-01c: Manual case submission succeeds

- GIVEN the user has selected case type "Subsidieaanvraag" and entered title "Innovatiesubsidie 2026"
- WHEN the user clicks "Create"
- THEN the system MUST create the case in the `procest` register with the `case` schema
- AND the `identifier` MUST be auto-generated
- AND the `startDate` MUST be set to today
- AND the `deadline` MUST be calculated as today + processingDeadline
- AND the `status` MUST be set to the first status type by order
- AND the user MUST be navigated to the new case's detail view

#### Scenario INTAKE-01d: Manual case with optional description

- GIVEN the user creates a case with only case type and title (description is empty)
- WHEN the case is created
- THEN the `description` field MUST be stored as empty/null
- AND the case MUST be created successfully

#### Scenario INTAKE-01e: Cancel case creation

- GIVEN the case creation dialog is open
- WHEN the user clicks "Cancel"
- THEN the dialog MUST close without creating a case
- AND no data MUST be persisted

---

### REQ-INTAKE-02: API-Driven Case Creation

The system MUST accept case creation requests via the ZGW Zaken API endpoint. Upon receiving a valid request, the system MUST instantiate the case with all behavioral controls from the case type.

**Feature tier**: MVP


#### Scenario INTAKE-02a: Successful API intake

- GIVEN a published case type "Omgevingsvergunning" with `processingDeadline = "P56D"` and initial status "Ontvangen"
- WHEN an external system sends `POST /zaken/api/v1/zaken` with `zaaktype`, `omschrijving`, and `startdatum`
- THEN the system MUST create the case in the `procest` register
- AND `identifier` MUST be auto-generated (format: `YYYY-NNN`)
- AND `deadline` MUST be calculated as `startdatum + P56D`
- AND `status` MUST be set to the first status type by `order`
- AND the system MUST return HTTP 201 with the case resource in ZGW format

#### Scenario INTAKE-02b: Reject intake with invalid zaaktype

- GIVEN a zaaktype URL that references a draft or expired case type
- WHEN an external system sends a create request
- THEN the system MUST return HTTP 400 with error: "Zaaktype is not published or not within its validity window"

#### Scenario INTAKE-02c: API intake with all optional fields

- GIVEN a valid create request including: zaaktype, omschrijving, startdatum, toelichting, vertrouwelijkheidaanduiding, zaakgeometrie
- WHEN the system processes the request
- THEN all provided fields MUST be mapped to the case properties (description, confidentiality, geometry)
- AND fields not provided MUST use defaults from the case type

#### Scenario INTAKE-02d: API authentication required

- GIVEN a create request without valid authentication (JWT or Basic Auth)
- WHEN the system receives the request
- THEN the system MUST return HTTP 401 Unauthorized
- AND no case MUST be created

#### Scenario INTAKE-02e: API intake returns ZGW-compliant response

- GIVEN a successful case creation via API
- WHEN the system returns the response
- THEN the response MUST include: `url` (self reference), `uuid`, `identificatie`, `omschrijving`, `zaaktype` (URL), `startdatum`, `status` (URL to first status), `einddatumGepland`
- AND the response MUST conform to the ZGW Zaken API response schema

---

### REQ-INTAKE-03: Automatic Behandelaar Assignment

The system SHALL support automatic assignment of a behandelaar based on case type configuration.

**Feature tier**: MVP


#### Scenario INTAKE-03a: Default handler from case type

- GIVEN a case type "Subsidieaanvraag" with `defaultAssignee = "team-subsidies"` (a Nextcloud group)
- WHEN a new case of this type is created via any channel
- THEN the system MUST assign the case to the configured default assignee
- AND a Nextcloud notification MUST be sent: "Nieuwe zaak toegewezen: [title]"

#### Scenario INTAKE-03b: Round-robin assignment within team

- GIVEN a case type with `assignmentStrategy = "round-robin"` and team members ["Jan", "Maria", "Pieter"]
- AND Jan has 5 open cases, Maria has 3, Pieter has 4
- WHEN a new case is created
- THEN the system SHOULD assign to Maria (lowest workload)

#### Scenario INTAKE-03c: No default assignee configured

- GIVEN a case type with no `defaultAssignee` configured
- WHEN a new case is created
- THEN the case MUST be created without an assignee
- AND the case MUST appear as "Unassigned" in the case list
- AND the dashboard MUST count this case in the "unassigned" category

#### Scenario INTAKE-03d: Assignment notification delivery

- GIVEN a case assigned to handler "Jan de Vries"
- WHEN the assignment is made
- THEN the system MUST send a Nextcloud notification to Jan
- AND the notification MUST include: case title, case type, and a link to the case detail
- AND the notification MUST be visible in Jan's Nextcloud notification panel

#### Scenario INTAKE-03e: Group assignment shows in case list

- GIVEN a case assigned to group "team-subsidies" (3 members)
- WHEN any member of team-subsidies views the case list
- THEN the case MUST appear in their "My Work" view
- AND the handler field MUST show "team-subsidies" until a specific person claims the case

---

### REQ-INTAKE-04: Initiator Role Creation

The system MUST support linking an initiator (aanvrager) to a case during intake.

**Feature tier**: MVP


#### Scenario INTAKE-04a: Manual initiator assignment

- GIVEN a case created via manual entry
- WHEN the handler opens the participants panel and clicks "Add Participant"
- THEN the handler MUST be able to select role "Aanvrager"
- AND search for a person (BRP lookup) or enter a name manually
- AND the selected person MUST be linked to the case with role "Aanvrager"

#### Scenario INTAKE-04b: API intake with initiator BSN

- GIVEN a ZGW API case creation request that includes a subsequent `POST /zaken/api/v1/rollen` with `betrokkeneType = "natuurlijk_persoon"` and BSN
- WHEN the system processes the request
- THEN the initiator MUST be created as a case participant with role "Aanvrager"
- AND the BSN MUST be stored (encrypted per AVG requirements)
- AND the initiator name MUST be resolved from BRP if available

#### Scenario INTAKE-04c: API intake with initiator organization (KVK)

- GIVEN a ZGW API role creation with `betrokkeneType = "niet_natuurlijk_persoon"` and KVK number
- WHEN the system processes the request
- THEN the organization MUST be linked as a case participant
- AND the organization name MUST be resolved from KVK if available

---

### REQ-INTAKE-05: Initial Task Creation

The system SHALL support automatic creation of initial tasks when a case is created, based on the case type configuration.

**Feature tier**: V1


#### Scenario INTAKE-05a: Auto-create intake checklist tasks

- GIVEN a case type "Omgevingsvergunning" with initial tasks configured: ["Ontvangstbevestiging versturen", "Compleetheid toetsen", "Leges berekenen"]
- WHEN a new case of this type is created
- THEN the system MUST create 3 tasks linked to the case
- AND each task MUST have status "available" and be assigned to the case handler
- AND each task MUST have a due date relative to the case start date (if configured)

#### Scenario INTAKE-05b: Task template with relative due date

- GIVEN a task template "Ontvangstbevestiging versturen" with relativeDueDate = "P3D"
- WHEN the task is auto-created for a case starting today
- THEN the task dueDate MUST be set to today + 3 days

#### Scenario INTAKE-05c: No initial tasks configured

- GIVEN a case type "Melding openbare ruimte" with no initial task templates
- WHEN a case is created
- THEN no tasks MUST be auto-created
- AND the tasks section in the case detail MUST show "No tasks"

#### Scenario INTAKE-05d: Initial tasks assigned to handler

- GIVEN a case type with initial tasks and defaultAssignee = "Jan de Vries"
- WHEN a case is created and Jan is assigned as handler
- THEN all auto-created tasks MUST be assigned to Jan
- AND Jan MUST receive a notification for each task (or a single summary notification)

---

### REQ-INTAKE-06: Open Formulieren Integration

The system MUST support receiving case submissions from Open Formulieren via ZGW API callback.

**Feature tier**: V1


#### Scenario INTAKE-06a: E-form submission creates case with attachments

- GIVEN Open Formulieren configured with Procest as ZGW backend
- AND a citizen submits form "Aanvraag omgevingsvergunning" with DigiD authentication
- WHEN the form engine calls `POST /zaken/api/v1/zaken` followed by document uploads
- THEN the system MUST create the case with the citizen as initiator (role type "Aanvrager")
- AND uploaded documents MUST be linked to the case
- AND BSN from DigiD MUST be stored on the initiator role (encrypted, AVG-compliant)
- AND the system MUST send an ontvangstbevestiging notification

#### Scenario INTAKE-06b: Form data mapped to custom properties

- GIVEN a form that submits structured data (bouwkosten, oppervlakte, adres)
- WHEN the case is created
- THEN the system MUST map form fields to case property definitions where names match
- AND unmapped fields MUST be stored as case metadata (not silently discarded)

#### Scenario INTAKE-06c: Open Formulieren with file attachments

- GIVEN a form submission with 3 PDF attachments (bouwtekening, constructieberekening, situatietekening)
- WHEN the form engine uploads these via `POST /documenten/api/v1/enkelvoudiginformatieobjecten`
- THEN each document MUST be stored in Nextcloud Files (IRootFolder)
- AND each document MUST be linked to the case via `zaakinformatieobjecten`
- AND the document type MUST be matched against the case type's document type configuration

#### Scenario INTAKE-06d: DigiD BSN encrypted storage

- GIVEN a citizen authenticates with DigiD (BSN 999993653)
- WHEN the BSN is stored on the initiator role
- THEN the BSN MUST be encrypted at rest
- AND the BSN MUST only be accessible to users with the appropriate RBAC permission
- AND access to BSN MUST be logged for AVG compliance

---

### REQ-INTAKE-07: Duplicate Detection

The system SHALL detect potential duplicate submissions to prevent double case creation.

**Feature tier**: V1


#### Scenario INTAKE-07a: Warn on potential duplicate

- GIVEN an existing case "Bouwvergunning Keizersgracht 100" for BSN 123456789
- WHEN a new submission arrives for the same BSN with similar title within 24 hours
- THEN the system MUST flag the intake as a potential duplicate
- AND the behandelaar MUST be notified: "Mogelijke dubbele aanvraag gedetecteerd"
- AND the case MUST still be created (not blocked) but marked for review

#### Scenario INTAKE-07b: Duplicate detection criteria

- GIVEN the duplicate detection system
- THEN a potential duplicate MUST be flagged when ANY of the following match within 24 hours:
  - Same BSN + same case type
  - Same BAG address + same case type
  - Title similarity > 80% (fuzzy match) + same case type
- AND the handler MUST be able to dismiss the duplicate flag after review

#### Scenario INTAKE-07c: Duplicate detection for API intake

- GIVEN two API calls creating cases with the same zaaktype and similar omschrijving within 1 minute
- WHEN the second case is created
- THEN the system MUST flag it as a potential duplicate
- AND the API response MUST include a header `X-Duplicate-Warning: possible` (but still return 201)

#### Scenario INTAKE-07d: Dismiss duplicate flag

- GIVEN a case flagged as potential duplicate
- WHEN the handler reviews both cases and determines they are distinct
- THEN the handler MUST be able to click "Not a duplicate" to dismiss the flag
- AND the flag MUST be removed from the case
- AND the audit trail MUST record: "Duplicate flag dismissed by [handler]"

---

### REQ-INTAKE-08: Intake Audit Trail

The system MUST record the intake channel and source metadata in the case audit trail.

**Feature tier**: MVP


#### Scenario INTAKE-08a: Record intake source for manual entry

- GIVEN a case created via the Procest UI
- WHEN the case is stored
- THEN the audit trail MUST record: intake channel "manual", created by user name, creation timestamp

#### Scenario INTAKE-08b: Record intake source for API intake

- GIVEN a case created via the ZGW API
- WHEN the case is stored
- THEN the audit trail MUST record: intake channel "zgw-api", authenticated client ID, source IP, creation timestamp

#### Scenario INTAKE-08c: Record intake source for Open Formulieren

- GIVEN a case created via Open Formulieren
- WHEN the case is stored
- THEN the audit trail MUST record: intake channel "open-formulieren", source form ID, submission timestamp, initiator BSN (hashed)
- AND this information MUST be queryable for reporting (e.g., "how many cases came from e-forms this month")

#### Scenario INTAKE-08d: Intake channel visible on case detail

- GIVEN a case created via Open Formulieren
- WHEN the handler views the case detail
- THEN the case info panel MUST show the intake channel (e.g., "Bron: Open Formulieren")
- AND clicking the source link SHOULD navigate to the original form submission (if available)

---

### REQ-INTAKE-09: E-mail Intake

The system SHALL support creating cases from incoming e-mails via n8n workflow automation.

**Feature tier**: V1


#### Scenario INTAKE-09a: E-mail triggers case creation

- GIVEN an n8n workflow configured to monitor an IMAP mailbox (e.g., info@gemeente.nl)
- AND the workflow contains rules to categorize e-mails by subject keywords
- WHEN an e-mail arrives with subject "Klacht over geluidsoverlast Keizersgracht"
- THEN the workflow MUST create a case of type "Klacht behandeling" via the ZGW API
- AND the e-mail body MUST be stored as the case description
- AND e-mail attachments MUST be uploaded as case documents

#### Scenario INTAKE-09b: E-mail sender as initiator

- GIVEN an incoming e-mail from "petra.jansen@example.nl"
- WHEN the case is created
- THEN the sender MUST be linked as initiator (role "Aanvrager") with their e-mail address
- AND if the e-mail matches a known contact (Pipelinq client), the system SHOULD auto-link

#### Scenario INTAKE-09c: E-mail intake with unknown category

- GIVEN an incoming e-mail that does not match any categorization rules
- WHEN the n8n workflow processes it
- THEN the workflow MUST create a case with a default case type (e.g., "Melding openbare ruimte")
- AND the case MUST be flagged for manual categorization by the behandelaar

---

### REQ-INTAKE-10: Bulk Import

The system SHALL support batch case creation via CSV or JSON file upload for migration or seasonal intake scenarios.

**Feature tier**: V1


#### Scenario INTAKE-10a: CSV bulk import

- GIVEN an admin uploads a CSV file with columns: title, caseType, description, startDate, assignee
- AND the CSV contains 50 rows
- WHEN the admin initiates the import
- THEN the system MUST validate all rows before creating any cases
- AND validation errors MUST be reported per row (e.g., "Row 12: invalid case type 'Onbekend'")
- AND the admin MUST confirm import after reviewing the validation report

#### Scenario INTAKE-10b: Bulk import with validation errors

- GIVEN a CSV with 50 rows, 3 of which have invalid case types
- WHEN the admin views the validation report
- THEN the system MUST show: "47 rows valid, 3 rows with errors"
- AND the admin MUST choose: import valid rows only, fix errors and retry, or cancel

#### Scenario INTAKE-10c: Bulk import progress

- GIVEN 47 valid cases being imported
- WHEN the import is in progress
- THEN the system MUST show a progress indicator (e.g., "23/47 cases created...")
- AND the system MUST handle failures gracefully (partial import is acceptable, failed rows are reported)

---

### REQ-INTAKE-11: Intake Channel Selection for Manual Entry

The manual case creation form MUST allow recording the original intake channel even when the case is entered manually.

**Feature tier**: MVP


#### Scenario INTAKE-11a: Record intake channel on manual case

- GIVEN a behandelaar creating a case from a phone call
- WHEN the case creation form is displayed
- THEN an optional "Intake Channel" dropdown MUST be available with options: "Balie" (counter), "Telefoon" (phone), "E-mail", "Post", "Website", "Overig" (other)
- AND the selected channel MUST be stored on the case metadata

#### Scenario INTAKE-11b: Default channel for manual entry

- GIVEN a case created via the UI without selecting an intake channel
- WHEN the case is stored
- THEN the intake channel MUST default to "Manual" in the audit trail
- AND the case info panel MUST show "Bron: Handmatig"

#### Scenario INTAKE-11c: Intake channel reporting

- GIVEN 50 cases created this month via various channels
- WHEN an admin views intake channel statistics
- THEN the system MUST be able to aggregate: X cases from Balie, Y from Telefoon, Z from E-mail, etc.
- AND this data MUST be available via the OpenRegister API for reporting tools

## Dependencies

- **Case Management spec** (`../case-management/spec.md`): Intake creates cases; all case validation rules apply.
- **Case Types spec** (`../case-types/spec.md`): Case type controls intake behavior (default assignee, initial tasks, required fields).
- **Task Management spec** (`../task-management/spec.md`): Initial tasks are created per task spec.
- **Roles & Decisions spec** (`../roles-decisions/spec.md`): Initiator role is created during intake.
- **OpenRegister**: All case data stored as OpenRegister objects.
- **OpenConnector**: ZGW API endpoint routing and StUF translation.
- **n8n**: E-mail intake workflow automation.

---

### Using Mock Register Data

This spec depends on the **BAG** and **DSO** mock registers for testing address validation and DSO intake (REQ-INTAKE-06, V1).

**Loading the registers:**
```bash
# Load BAG register (32 addresses + 21 objects + 21 buildings, register slug: "bag")
docker exec -u www-data nextcloud php occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/bag_register.json

# Load DSO register (53 records, register slug: "dso", schemas: "activiteit", "locatie", "omgevingsdocument", "vergunningaanvraag")
docker exec -u www-data nextcloud php occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/dso_register.json

# Load BRP register for initiator BSN linking
docker exec -u www-data nextcloud php occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/brp_register.json
```

**Test data for this spec's use cases:**
- **DSO intake (REQ-INTAKE-06)**: Use DSO `vergunningaanvraag` records to test omgevingsvergunning case creation with activiteiten, locatie, and bijlagen
- **BAG address validation**: Use BAG `nummeraanduiding` records to test address resolution in form-to-case mapping
- **Initiator with BSN**: BSN `999993653` (Suzanne Moulin) -- test initiator role creation with BSN from Open Formulieren DigiD flow
- **DSO activiteiten**: Use DSO `activiteit` records (e.g., "Dakkapel plaatsen", "Aanbouw") for activity-to-zaaktype mapping

### Current Implementation Status

**Partially implemented (manual intake + ZGW API intake). V1 features not implemented.**

**Implemented (with file paths):**
- **Manual case creation (REQ-INTAKE-01)**: `src/views/cases/CaseCreateDialog.vue` provides a UI form for creating new cases. Users select a case type, fill in title, description, and other fields. The case is created via the object store against OpenRegister.
- **ZGW API case creation (REQ-INTAKE-02)**: `lib/Controller/ZrcController.php` provides `POST /api/zgw/zaken/v1/zaken` endpoint for external systems to create cases via ZGW-compliant API. Supports zaaktype reference, omschrijving, startdatum, and other ZGW fields.
- **ZGW business rules**: `lib/Service/ZgwBusinessRulesService.php` and `lib/Service/ZgwZrcRulesService.php` implement validation rules for case creation, including zaaktype validation, status initialization, and field mapping.
- **ZGW mapping**: `lib/Service/ZgwMappingService.php` handles bidirectional mapping between ZGW Dutch terminology and Procest English field names.
- **ZGW auth**: `lib/Middleware/ZgwAuthMiddleware.php` provides JWT-based authentication for ZGW API endpoints, allowing external systems to authenticate.
- **Case type validation**: `src/utils/caseTypeValidation.js` provides client-side validation for case type data. `lib/Service/ZgwZtcRulesService.php` validates zaaktype status (draft/published, validity window).
- **Deadline calculation**: The case type's `processingDeadline` (ISO 8601 duration) is used to calculate the case deadline. `src/utils/durationHelpers.js` supports ISO 8601 duration parsing. `src/views/cases/components/DeadlinePanel.vue` displays the calculated deadline.
- **Status initialization**: New cases get their status set to the first status type (by order) of the case type. Implemented in ZGW business rules and CaseCreateDialog.
- **Audit trail**: Case creation is logged via OpenRegister's audit trail. The `auditTrailsPlugin()` in the object store captures creation events (REQ-INTAKE-08).
- **ZGW notification**: `lib/Controller/NrcController.php` and `lib/Service/NotificatieService.php` support ZGW notification webhooks for case lifecycle events.
- **Identifier generation**: Case identifiers can be auto-generated (format depends on configuration).

**Not yet implemented:**
- **REQ-INTAKE-03: Automatic behandelaar assignment**: No default assignee configuration on case types. No round-robin assignment strategy. Cases are created without an assignee unless manually set.
- **REQ-INTAKE-04: Initiator role creation**: No automatic creation of initiator role during intake from API. The role must be manually added via the Participants section (or via separate ZGW rollen API call).
- **REQ-INTAKE-05: Initial task creation (V1)**: No automatic task creation based on case type configuration. No task templates on case types.
- **REQ-INTAKE-06: Open Formulieren integration (V1)**: No integration with Open Formulieren. No DigiD/BSN handling. No form-to-case field mapping.
- **REQ-INTAKE-07: Duplicate detection (V1)**: No duplicate submission detection.
- **REQ-INTAKE-09: E-mail intake (V1)**: No IMAP trigger or e-mail-to-case conversion.
- **REQ-INTAKE-10: Bulk import (V1)**: No CSV/JSON bulk case creation.
- **REQ-INTAKE-11: Intake channel selection**: No intake channel dropdown on the manual creation form.
- **Assignment notification**: No Nextcloud notification sent when a case is assigned to a handler.

### Standards & References

- **ZGW Zaken API**: `POST /zaken/api/v1/zaken` implemented via `ZrcController.php`. Supports the ZGW case creation flow with zaaktype validation, status initialization, and field mapping.
- **StUF-ZKN**: `creeerZaak_Lk01` message format not implemented. StUF translation would require OpenConnector.
- **CMMN 1.1**: Case instantiation follows CasePlanModel patterns with status lifecycle initialization.
- **Common Ground**: Intake channels (API, forms, DSO) align with Common Ground information layer principles.
- **Open Formulieren**: VNG's open-source form engine for citizen-facing e-forms. Integration via ZGW API callback.
- **DSO (Digitaal Stelsel Omgevingswet)**: Environmental law digital system for permit applications.
- **DigiD/eHerkenning**: Dutch government authentication for citizens (DigiD) and organizations (eHerkenning). BSN handling requires AVG-compliant encryption.
- **AVG/GDPR**: BSN storage must be encrypted, access logged, and retention limited.
- **Competitor reference**: Dimpact ZAC integrates with Open Formulieren and SmartDocuments for intake. CaseFabric provides multi-channel intake with auto-categorization. XXllnc Zaken supports DSO intake and StUF-ZKN for legacy system compatibility.
