# Zaak Intake Flow Specification

## Purpose

The zaak intake flow governs what happens after a case is initiated -- whether from Open Formulieren, DSO/Omgevingsloket, manual entry, or API call. It handles automatic zaaktype assignment, status initialization, initial task creation, notification to the assigned behandelaar, and linking of the initiator. This is the bridge between external input and the internal case lifecycle.

**Tender demand**: 61% of tenders (42/69) require formulieren/intake capabilities. Automatic case creation from external submissions is a baseline expectation.
**Standards**: ZGW Zaken API (`zaak-create`), StUF-ZKN (`creeerZaak_Lk01`), CMMN 1.1 (CasePlanModel instantiation)
**Feature tier**: MVP (manual + API intake, zaaktype assignment, status init, behandelaar notification), V1 (Open Formulieren integration, DSO intake, duplicate detection, batch intake)

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

### REQ-INTAKE-01: API-Driven Case Creation

**Feature tier**: MVP

The system MUST accept case creation requests via the ZGW Zaken API endpoint. Upon receiving a valid request, the system MUST instantiate the case with all behavioral controls from the case type.

#### Scenario INTAKE-01a: Successful API intake

- GIVEN a published case type "Omgevingsvergunning" with `processingDeadline = "P56D"` and initial status "Ontvangen"
- WHEN an external system sends `POST /zaken/api/v1/zaken` with `zaaktype`, `omschrijving`, and `startdatum`
- THEN the system MUST create the case in the `procest` register
- AND `identifier` MUST be auto-generated (format: `YYYY-NNN`)
- AND `deadline` MUST be calculated as `startdatum + P56D`
- AND `status` MUST be set to the first status type by `order`
- AND the system MUST return HTTP 201 with the case resource

#### Scenario INTAKE-01b: Reject intake with invalid zaaktype

- GIVEN a zaaktype URL that references a draft or expired case type
- WHEN an external system sends a create request
- THEN the system MUST return HTTP 400 with error: "Zaaktype is not published or not within its validity window"

---

### REQ-INTAKE-02: Automatic Behandelaar Assignment

**Feature tier**: MVP

The system SHOULD support automatic assignment of a behandelaar based on case type configuration.

#### Scenario INTAKE-02a: Default handler from case type

- GIVEN a case type "Subsidieaanvraag" with `defaultAssignee = "team-subsidies"` (a Nextcloud group)
- WHEN a new case of this type is created via any channel
- THEN the system MUST assign the case to the configured default assignee
- AND a Nextcloud notification MUST be sent: "Nieuwe zaak toegewezen: [title]"

#### Scenario INTAKE-02b: Round-robin assignment within team

- GIVEN a case type with `assignmentStrategy = "round-robin"` and team members ["Jan", "Maria", "Pieter"]
- AND Jan has 5 open cases, Maria has 3, Pieter has 4
- WHEN a new case is created
- THEN the system SHOULD assign to Maria (lowest workload)

---

### REQ-INTAKE-03: Initial Task Creation

**Feature tier**: V1

The system SHOULD support automatic creation of initial tasks when a case is created, based on the case type configuration.

#### Scenario INTAKE-03a: Auto-create intake checklist tasks

- GIVEN a case type "Omgevingsvergunning" with initial tasks configured: ["Ontvangstbevestiging versturen", "Compleetheid toetsen", "Leges berekenen"]
- WHEN a new case of this type is created
- THEN the system MUST create 3 tasks linked to the case
- AND each task MUST have status "available" and be assigned to the case handler
- AND each task MUST have a due date relative to the case start date (if configured)

---

### REQ-INTAKE-04: Open Formulieren Integration

**Feature tier**: V1

The system MUST support receiving case submissions from Open Formulieren via ZGW API callback.

#### Scenario INTAKE-04a: E-form submission creates case with attachments

- GIVEN Open Formulieren configured with Procest as ZGW backend
- AND a citizen submits form "Aanvraag omgevingsvergunning" with DigiD authentication
- WHEN the form engine calls `POST /zaken/api/v1/zaken` followed by document uploads
- THEN the system MUST create the case with the citizen as initiator (role type "Aanvrager")
- AND uploaded documents MUST be linked to the case
- AND BSN from DigiD MUST be stored on the initiator role (encrypted, AVG-compliant)
- AND the system MUST send an ontvangstbevestiging notification

#### Scenario INTAKE-04b: Form data mapped to custom properties

- GIVEN a form that submits structured data (bouwkosten, oppervlakte, adres)
- WHEN the case is created
- THEN the system MUST map form fields to case property definitions where names match
- AND unmapped fields MUST be stored as case metadata (not silently discarded)

---

### REQ-INTAKE-05: Duplicate Detection

**Feature tier**: V1

The system SHOULD detect potential duplicate submissions to prevent double case creation.

#### Scenario INTAKE-05a: Warn on potential duplicate

- GIVEN an existing case "Bouwvergunning Keizersgracht 100" for BSN 123456789
- WHEN a new submission arrives for the same BSN with similar title within 24 hours
- THEN the system MUST flag the intake as a potential duplicate
- AND the behandelaar MUST be notified: "Mogelijke dubbele aanvraag gedetecteerd"
- AND the case MUST still be created (not blocked) but marked for review

---

### REQ-INTAKE-06: Intake Audit Trail

**Feature tier**: MVP

The system MUST record the intake channel and source metadata in the case audit trail.

#### Scenario INTAKE-06a: Record intake source

- GIVEN a case created via Open Formulieren
- WHEN the case is stored
- THEN the audit trail MUST record: intake channel "open-formulieren", source form ID, submission timestamp, initiator BSN (hashed)
- AND this information MUST be queryable for reporting (e.g., "how many cases came from e-forms this month")

## Dependencies

- **Case Management spec** (`../case-management/spec.md`): Intake creates cases; all case validation rules apply.
- **Case Types spec** (`../case-types/spec.md`): Case type controls intake behavior (default assignee, initial tasks, required fields).
- **Task Management spec** (`../task-management/spec.md`): Initial tasks are created per task spec.
- **Roles & Decisions spec** (`../roles-decisions/spec.md`): Initiator role is created during intake.
- **OpenRegister**: All case data stored as OpenRegister objects.
- **OpenConnector**: ZGW API endpoint routing and StUF translation.

---

### Using Mock Register Data

This spec depends on the **BAG** and **DSO** mock registers for testing address validation and DSO intake (REQ-INTAKE-04, V1).

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
- **DSO intake (REQ-INTAKE-04)**: Use DSO `vergunningaanvraag` records to test omgevingsvergunning case creation with activiteiten, locatie, and bijlagen
- **BAG address validation**: Use BAG `nummeraanduiding` records to test address resolution in form-to-case mapping
- **Initiator with BSN**: BSN `999993653` (Suzanne Moulin) -- test initiator role creation with BSN from Open Formulieren DigiD flow
- **DSO activiteiten**: Use DSO `activiteit` records (e.g., "Dakkapel plaatsen", "Aanbouw") for activity-to-zaaktype mapping

### Current Implementation Status

**Partially implemented (manual intake + ZGW API intake). V1 features not implemented.**

**Implemented (with file paths):**
- **Manual case creation**: `src/views/cases/CaseCreateDialog.vue` provides a UI form for creating new cases. Users select a case type, fill in title, description, and other fields. The case is created via the object store against OpenRegister.
- **ZGW API case creation**: `lib/Controller/ZrcController.php` provides `POST /api/zgw/zaken/v1/zaken` endpoint for external systems to create cases via ZGW-compliant API. Supports zaaktype reference, omschrijving, startdatum, and other ZGW fields (REQ-INTAKE-01).
- **ZGW business rules**: `lib/Service/ZgwBusinessRulesService.php` and `lib/Service/ZgwZrcRulesService.php` implement validation rules for case creation, including zaaktype validation, status initialization, and field mapping.
- **ZGW mapping**: `lib/Service/ZgwMappingService.php` handles bidirectional mapping between ZGW Dutch terminology and Procest English field names.
- **ZGW auth**: `lib/Middleware/ZgwAuthMiddleware.php` provides JWT-based authentication for ZGW API endpoints, allowing external systems to authenticate.
- **Case type validation**: `src/utils/caseTypeValidation.js` provides client-side validation for case type data. `lib/Service/ZgwZtcRulesService.php` validates zaaktype status (draft/published, validity window).
- **Deadline calculation**: The case type's `processingDeadline` (ISO 8601 duration) is used to calculate the case deadline. `src/utils/durationHelpers.js` supports ISO 8601 duration parsing. `src/views/cases/components/DeadlinePanel.vue` displays the calculated deadline.
- **Status initialization**: New cases get their status set to the first status type (by order) of the case type. Implemented in ZGW business rules and CaseCreateDialog.
- **Audit trail**: Case creation is logged via OpenRegister's audit trail. The `auditTrailsPlugin()` in the object store captures creation events (REQ-INTAKE-06).
- **ZGW notification**: `lib/Controller/NrcController.php` and `lib/Service/NotificatieService.php` support ZGW notification webhooks for case lifecycle events.
- **Identifier generation**: Case identifiers can be auto-generated (format depends on configuration).

**Not yet implemented:**
- **REQ-INTAKE-02: Automatic behandelaar assignment**: No default assignee configuration on case types. No round-robin assignment strategy. Cases are created without an assignee unless manually set.
- **REQ-INTAKE-03: Initial task creation (V1)**: No automatic task creation based on case type configuration. No task templates on case types.
- **REQ-INTAKE-04: Open Formulieren integration (V1)**: No integration with Open Formulieren. No DigiD/BSN handling. No form-to-case field mapping.
- **REQ-INTAKE-05: Duplicate detection (V1)**: No duplicate submission detection.
- **E-mail intake**: No IMAP trigger or e-mail-to-case conversion.
- **Bulk import**: No CSV/JSON bulk case creation.
- **DSO/Omgevingsloket intake**: No StUF-LVO integration. No DSO verzoek processing.
- **Initiator role creation**: No automatic creation of initiator role during intake. The role must be manually added via the Participants section.
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

### Specificity Assessment

- **MVP (manual + API intake) is well-specified and implemented.** The ZGW API intake flow is clear and the implementation covers the core scenarios.
- **V1 features need more detail:**
  - Open Formulieren integration needs the specific webhook format, field mapping rules, and DigiD data handling.
  - Duplicate detection needs the matching criteria (BSN + title similarity? Address? Time window?).
  - Automatic task creation depends on the task template spec (not yet defined in detail).
- **Open questions:**
  - How does the round-robin assignment algorithm handle unavailable team members (vacation, sick leave)?
  - Should automatic behandelaar assignment be configurable per case type or global?
  - How is BSN encrypted and stored? (OpenRegister field-level encryption? Separate encrypted store?)
  - Should intake audit metadata (channel, source form ID) be stored as case properties or as a separate audit object?
  - How does bulk import handle validation errors (reject entire batch, skip invalid records, or import with warnings)?
