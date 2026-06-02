# Tasks: vth-workflow-configuration

Implementation tasks for VTH workflow configuration, covering workflow templates, leges calculation, beschikking generation, DSO integration, and admin configuration interfaces.

---

## 1. Workflow Template Configuration

### Task 1: Create Omgevingsvergunning Workflow Template
**Spec ref**: REQ-VTH-001, REQ-VTH-005
**Files**: 
- `lib/Settings/templates/vth-omgevingsvergunning-workflow.json`
- `lib/Service/VTHWorkflowService.php` (add method)
**Acceptance criteria**:
- GIVEN admin loads template WHEN activates THEN statuses (Aanvraag ontvangen, In behandeling, ..., Verleend) and roles (Vergunningverlener, Juridisch adviseur) are created
- Workflow JSON is valid OpenAPI 3.0 + `x-openregister` extension
- Template is idempotent on re-activation

- [ ] Author workflow JSON with steps, transitions, guards for Omgevingsvergunning
- [ ] Include status definitions: Aanvraag ontvangen, In behandeling, Advies aangevraagd, Beschikking opgesteld, Verzonden, Verleend, Geweigerd, Ingetrokken, Afgehandeld
- [ ] Add role definitions: Vergunningverlener, Juridisch adviseur, Administratief medewerker
- [ ] Add document type requirements: Aanvraagformulier, Tekeningen, Beschikking
- [ ] Test template validation (OpenAPI + x-openregister)
- [ ] Register template in `VTHWorkflowService.loadTemplate()`
- [ ] Test idempotent re-activation (no duplicate statuses/roles created)

### Task 2: Create Toezichtzaak Workflow Template
**Spec ref**: REQ-VTH-002
**Files**:
- `lib/Settings/templates/vth-toezichtzaak-workflow.json`
- `lib/Service/VTHWorkflowService.php` (extend)
**Acceptance criteria**:
- GIVEN admin loads template WHEN activates THEN statuses (Geplande inspectie, In voortgang, Rapport opstellen, Afgerond) are created
- Roles: Inspector, Coördinator toezicht, Rapporteur

- [ ] Author workflow JSON with statuses: Geplande inspectie, In voorbereiding, Inspectie in voortgang, Rapport opstellen, Afgerond
- [ ] Add roles: Inspector, Coördinator toezicht, Rapporteur
- [ ] Add properties: inspectionType enum, location reference, checklist reference
- [ ] Add transitions with guards (e.g., GPS location required to transition to Rapport)
- [ ] Register template in `VTHWorkflowService`
- [ ] Test workflow activation

### Task 3: Create Handhavingszaak Workflow Template
**Spec ref**: REQ-VTH-003
**Files**:
- `lib/Settings/templates/vth-handhavingszaak-workflow.json`
- `lib/Service/VTHWorkflowService.php` (extend)
**Acceptance criteria**:
- GIVEN admin loads template WHEN activates THEN statuses (Onderzoek, Geclassificeerd, Waarschuwing, Aanzegging, Dwangsom, Afgerond) are created
- Roles: Handhaver, Manager handhaving, Juridisch adviseur

- [ ] Author workflow JSON with statuses: Onderzoek, Geclassificeerd, Waarschuwing verstuurd, Aanzegging verstuurd, Dwangsom opgelegd, Afgerond, Ingetrokken
- [ ] Add roles: Handhaver, Manager handhaving, Juridisch adviseur
- [ ] Add properties: violation (text), gedrag (LHSO axis), gevolgen (LHSO axis), lhsoSuggestion, interventieActual, overrideReason
- [ ] Add transitions with override validation (require overrideReason when intervention ≠ suggestion)
- [ ] Register template in `VTHWorkflowService`

---

## 2. Leges (Fee) Calculation Engine

### Task 4: Create LegesCalculationService
**Spec ref**: REQ-VTH-004
**Files**:
- `lib/Service/LegesCalculationService.php`
- `lib/Controller/LegesController.php`
- `lib/Settings/leges_register.json` (seed data)
**Acceptance criteria**:
- GIVEN case with zaaktype=Omgevingsvergunning, activiteit=Verbouwing, size=250m² WHEN calculated THEN fee = €700 (base €500 + size modifier €200)
- Verrekening: prior fees offset correctly; final fee = calculated - offset
- Teruggaaf: 100% refund if withdrawn before beschikking; audit recorded
- Navordering: additional fees tracked and notification sent

- [ ] Implement `LegesCalculationService.calculateFee(caseId)` → returns {baseFee, modifiers, totalFee, rules}
- [ ] Implement `applyVerrekening(caseId, priorFee)` → returns {offset, finalFee}
- [ ] Implement `refund(caseId, reason)` → returns {refundAmount, status}
- [ ] Implement `navordering(caseId, amount, reason)` → records additional fee
- [ ] Create `LegesController` with endpoints: POST /api/vth/leges/calculate, POST /api/vth/cases/{id}/leges/verrekening, etc.
- [ ] Seed leges rule sets for Omgevingsvergunning, Toezichtzaak (basic sets; expandable)
- [ ] Test calculation with all modifier types
- [ ] Test verrekening offset logic
- [ ] Test refund conditions and notification
- [ ] Audit trail: log all leges transactions (calculation, verrekening, refund, navordering)

### Task 5: Leges Rule Configuration UI
**Spec ref**: REQ-VTH-008-B
**Files**:
- `src/views/settings/tabs/LegesRulesTab.vue`
- `src/components/LegesRuleEditor.vue`
**Acceptance criteria**:
- Admin can edit: base fee, modifiers (property × amount), exemptions, verrekening rules, teruggaaf rules
- On save: new rule version created; prior marked validUntil=today; new marked validFrom=tomorrow
- UI confirms "Leges rules updated; effective for new cases from tomorrow"

- [ ] Create LegesRulesTab component showing list of rule sets
- [ ] Build LegesRuleEditor with fields: base fee (currency), modifiers list (add/edit/delete), exemptions, verrekening, teruggaaf
- [ ] Implement rule validation (amounts ≥0, no duplicate modifiers)
- [ ] On save: call LegesService to version rule set
- [ ] Test rule versioning (old → validUntil, new → validFrom)
- [ ] Test effective date calculation (tomorrow)
- [ ] Test existing case calculation remains with old rule version

---

## 3. Beschikking (Permit Decision) Generation

### Task 6: Create BeschikkingGenerationService
**Spec ref**: REQ-VTH-005
**Files**:
- `lib/Service/BeschikkingGenerationService.php`
- `lib/Controller/BeschikkingController.php`
**Acceptance criteria**:
- GIVEN case with all required fields (applicantName, location, activities, conditions) WHEN generated THEN beschikking PDF is created with merged fields
- Validation: missing required fields → error message with field name; generation blocked
- Template versioning: old template (validUntil < today) not used for new generations

- [ ] Implement `BeschikkingGenerationService.generateBeschikking(caseId, decisionType)`
- [ ] Load beschikkingTemplate for (caseType, decisionType)
- [ ] Validate required fields; return error if missing
- [ ] Implement merge field substitution ({{applicantName}} → case data)
- [ ] Generate HTML/PDF (use HTML-to-PDF fallback if Docudesk unavailable)
- [ ] Save generated document via FileService
- [ ] Attach document to case bijlagen relation
- [ ] Implement `BeschikkingController.generate()` endpoint
- [ ] Test all merge fields with sample data
- [ ] Test required field validation
- [ ] Test template versioning (new generations use currentVersion only)
- [ ] Test Docudesk integration (if available)

### Task 7: Beschikking Template Management UI
**Spec ref**: REQ-VTH-008-C
**Files**:
- `src/views/settings/tabs/BeschikkingTemplatesTab.vue`
- `src/components/BeschikkingTemplateEditor.vue`
**Acceptance criteria**:
- Admin can create, edit, view, delete beschikking templates
- Merge field picker available (autocomplete or drag-drop)
- Test generation with sample data
- Set validity dates (validFrom, validUntil)

- [ ] Create BeschikkingTemplatesTab showing templates by zaaktype and decisionType
- [ ] Build BeschikkingTemplateEditor with:
  - Name and description
  - Decision type selector (Verleend, Geweigerd, etc.)
  - Template content editor (with merge field picker)
  - List of available merge fields for zaaktype (from schema)
  - Validity date picker
- [ ] Implement merge field insertion (autocomplete, drag-drop, or button-insert)
- [ ] Add "Test generation" button with sample data selector
- [ ] On save: call BeschikkingService to version template
- [ ] Test template creation, editing, validity dates

---

## 4. Mobile Inspection Workflow

### Task 8: Create MobileInspectionService
**Spec ref**: REQ-VTH-002
**Files**:
- `lib/Service/MobileInspectionService.php`
- `lib/Controller/MobileInspectionController.php`
**Acceptance criteria**:
- GIVEN inspector requests checklist on mobile WHEN returned THEN responsive view shows items with type-specific inputs
- Photo upload: Nextcloud file ID recorded; GPS coordinates captured with timestamp
- Submission: all required items answered; required photos attached; returns InspectionResult

- [ ] Implement `MobileInspectionService.getChecklist(caseId)` → returns formatted checklist for mobile
- [ ] Implement photo upload handling (compress for mobile, store in Nextcloud)
- [ ] Implement GPS capture and fallback (manual entry if unavailable)
- [ ] Implement `submitInspectionResult(caseId, answers, photos, gps)` → creates inspectionResult
- [ ] Validation: required items answered, required photos attached
- [ ] Create `MobileInspectionController` with endpoints: GET /api/vth/cases/{id}/mobile/checklist, POST .../mobile/inspection-result
- [ ] Test checklist retrieval with various item types
- [ ] Test photo upload and Nextcloud storage
- [ ] Test GPS capture and manual entry fallback
- [ ] Test validation errors (missing required items/photos)

### Task 9: Mobile Inspection UI (Responsive View)
**Spec ref**: REQ-VTH-002
**Files**:
- `src/views/cases/MobileInspectionView.vue`
- `src/components/MobileChecklistItem.vue`
- `src/components/PhotoUploadInput.vue`
- `src/components/GpsLocationInput.vue`
**Acceptance criteria**:
- Responsive single-column layout optimized for mobile devices
- Each item shows question, type-specific input (checkbox, text, photo button, GPS button)
- Progress bar: "3/12 items completed"
- Navigation: Previous, Next, Submit buttons
- Offline mode: answer offline; sync when connectivity returns

- [ ] Create MobileInspectionView component
- [ ] Implement responsive layout (single column, touch-friendly buttons)
- [ ] Build MobileChecklistItem component (renders question + input based on type)
- [ ] Create PhotoUploadInput: file picker, preview, compression, upload feedback
- [ ] Create GpsLocationInput: GPS capture button, manual entry fallback, map preview
- [ ] Implement progress bar and navigation (Prev/Next/Submit)
- [ ] Add offline support (localStorage for draft answers; sync on connectivity)
- [ ] Test on mobile device (iPhone, Android) for touch responsiveness
- [ ] Test photo upload on slow connection (compression, progress feedback)
- [ ] Test GPS capture and fallback

### Task 10: Inspection Checklist Template Configuration UI
**Spec ref**: REQ-VTH-002-C
**Files**:
- `src/views/settings/tabs/InspectionChecklistsTab.vue`
- `src/components/InspectionChecklistEditor.vue`
**Acceptance criteria**:
- Admin can create checklist with name, case type
- Add items: question, type (boolean/enum/text/photo/gps), required flag, help text
- Reorder items via drag-drop
- Preview mobile view
- Save creates versioned checklist (v1, v2, etc.)

- [ ] Create InspectionChecklistsTab showing checklists by case type
- [ ] Build InspectionChecklistEditor with:
  - Name, description, case type selector
  - Items list (add/edit/delete rows)
  - Each row: question, type dropdown, required checkbox, help text
  - Drag-drop reordering
  - Preview button (shows mobile view)
  - Save/Cancel buttons
- [ ] Implement item validation (question required, type valid)
- [ ] On save: call InspectionService to create versioned checklist
- [ ] Test checklist creation, editing, reordering, preview

---

## 5. LHSO Classification and Enforcement

### Task 11: Seed LHSO Matrix Data
**Spec ref**: REQ-VTH-003
**Files**:
- `lib/Settings/lhso_matrix_seed.json`
- `lib/RepairStep/LhsoMatrixRepairStep.php`
**Acceptance criteria**:
- All 16 LHSO matrix cells seeded (Gedrag A–D × Gevolgen 1–4)
- Each cell includes: gedrag, gevolgen, interventieStep, description
- Data is loaded on install via repair step
- Idempotent: re-running repair step does not create duplicates

- [ ] Create lhso_matrix_seed.json with 16 entries:
  - Gedrag A (no knowledge), Gevolgen 1–4
  - Gedrag B (assumed knowledge), Gevolgen 1–4
  - Gedrag C (serious), Gevolgen 1–4
  - Gedrag D (very serious), Gevolgen 1–4
- [ ] Each entry: {gedrag, gevolgen, interventieStep, description}
- [ ] Sample interventies: Advies, Bestuurlijke waarschuwing, Aanzegging, Dwangsom
- [ ] Create repair step `LhsoMatrixRepairStep` to load seed data
- [ ] Test idempotency (re-running does not duplicate)
- [ ] Verify all 16 cells are queryable

### Task 12: Create LhsoLookupService
**Spec ref**: REQ-VTH-003-B
**Files**:
- `lib/Service/LhsoLookupService.php`
- `lib/Controller/LhsoController.php`
**Acceptance criteria**:
- `GET /api/vth/lhso/matrix` returns all 16 cells
- `GET /api/vth/lhso/lookup?gedrag=C&gevolgen=3` returns suggestion + description
- Lookup validates inputs (gedrag A–D, gevolgen 1–4)

- [ ] Implement `LhsoLookupService.lookup(gedrag, gevolgen)` → returns lhsoMatrixCell
- [ ] Implement `getMatrix()` → returns all 16 cells as 4x4 array
- [ ] Create `LhsoController` with endpoints: GET /api/vth/lhso/matrix, GET /api/vth/lhso/lookup
- [ ] Test lookup with all 16 combinations
- [ ] Test invalid inputs (gedrag=E, gevolgen=5) → error response

### Task 13: LHSO Classification UI (in Handhavingszaak Detail)
**Spec ref**: REQ-VTH-003-B, REQ-VTH-003-C
**Files**:
- `src/views/cases/components/LhsoClassificationPanel.vue`
**Acceptance criteria**:
- Panel shows LHSO matrix (4x4 grid or selector)
- Admin selects gedrag and gevolgen → suggestion appears ("Aanzegging dwanggeld")
- If intervention ≠ suggestion: overrideReason field becomes visible and required
- Save records classification and override (if any) to case

- [ ] Create LhsoClassificationPanel component
- [ ] Display LHSO matrix as 4x4 grid (gedrag rows, gevolgen columns)
- [ ] Click cell → suggestion appears in detail panel
- [ ] Show "Suggested intervention: {{interventieStep}}" with description
- [ ] Add intervention selector (dropdown or radio buttons)
- [ ] If intervention ≠ suggestion: show overrideReason textarea (required)
- [ ] Save button calls case service to record classification
- [ ] Test all 16 matrix selections
- [ ] Test override reason visibility and validation

---

## 6. DSO Integration

### Task 14: Create DSO Intake and Case Mapping Service
**Spec ref**: REQ-VTH-006-A, REQ-VTH-006-B
**Files**:
- `lib/Service/DsoIntakeService.php`
- `lib/Service/DsoCaseService.php`
- `lib/Listener/VergunningaanvraagCreatedListener.php`
**Acceptance criteria**:
- GIVEN DSO verzoek object created in OpenRegister WHEN listener triggered THEN case is auto-created with correct zaaktype and pre-filled data
- Data mapping: activities, location (BAG reference), initiator (BRP reference), documents/bijlagen
- Case transitions to "Aanvraag ontvangen" and notification sent
- If BRP lookup fails: case flagged "Awaiting manual initiator linking"

- [ ] Implement `DsoIntakeService.mapVerzoekToCase(vergunningaanvraag)` → returns case payload
- [ ] Map STAM 2.0 fields: activiteiten, locatie, initiatiefnemer, procedureType, bijlagen
- [ ] Resolve BRP/organization references (BSN → BRP lookup)
- [ ] Download and attach bijlagen to case via FileService
- [ ] Implement `DsoCaseService.createCaseFromVerzoek(vergunningaanvraag)` → creates and returns case
- [ ] Create `VergunningaanvraagCreatedListener` listening to ObjectCreatedEvent
- [ ] Listener: on event → call DsoCaseService.createCase()
- [ ] Register listener in `appinfo/info.xml`
- [ ] Test case creation with sample STAM 2.0 payload
- [ ] Test data mapping (activities, location, initiator)
- [ ] Test BRP lookup success and failure (manual linking flag)
- [ ] Test bijlagen download and attachment

### Task 15: Status Pushback to DSO-LV
**Spec ref**: REQ-VTH-006-C
**Files**:
- `lib/Event/VergunningStatusChangedEvent.php`
- `lib/Listener/StatusChangeDispatcherListener.php`
**Acceptance criteria**:
- GIVEN case status changes (e.g., "In behandeling" → "Beschikking opgesteld") WHEN transition executed THEN VergunningStatusChangedEvent is dispatched
- Event includes: vergunningaanvraagRef, oldStatus, newStatus, timestamp, userId
- OpenConnector listens and pushes to DSO-LV
- For Verleend/Geweigerd: beschikking attachment URL included

- [ ] Create `VergunningStatusChangedEvent` class with properties: vergunningaanvraagRef, oldStatus, newStatus, besluitdatum, toelichting, userId, beschikkingUrl
- [ ] Create `StatusChangeDispatcherListener` listening to case status transitions
- [ ] On DSO case status change → dispatch event
- [ ] For Verleend/Geweigerd → include beschikking document URL in event
- [ ] Register listener in `appinfo/info.xml`
- [ ] Test event dispatch on status changes
- [ ] Test event payload (all required fields)
- [ ] Test OpenConnector can listen and consume event

### Task 16: DSO Deadline Tracking and Warnings
**Spec ref**: REQ-VTH-006-D
**Files**:
- `lib/BackgroundJob/DsoDeadlineJob.php`
- `lib/Service/DsoDeadlineService.php`
**Acceptance criteria**:
- Daily job evaluates DSO cases against deadline
- At deadline - 6 weeks: notification sent "6 weeks remaining"
- At deadline - 2 weeks: notification sent "2 weeks remaining — escalate if not in beschikking stage"
- At deadline: case flagged "Overdue"; transitions blocked until escalation

- [ ] Create `DsoDeadlineService.evaluateDeadlines()` → evaluates all DSO cases
- [ ] Implement deadline calculation (8 weeks reguliere, 26 weeks uitgebreide; working days per OW)
- [ ] Implement warning thresholds (6 weeks, 2 weeks)
- [ ] Implement overdue flagging and escalation
- [ ] Create `DsoDeadlineJob` extending TimedJob (daily execution)
- [ ] Job calls `DsoDeadlineService.evaluateDeadlines()`
- [ ] Register job in `appinfo/info.xml`
- [ ] Test deadline calculation (correct working days)
- [ ] Test notification triggers at correct thresholds
- [ ] Test overdue flagging and escalation workflow

---

## 7. Admin Configuration and Settings

### Task 17: VTH Configuration Settings Page
**Spec ref**: REQ-VTH-008
**Files**:
- `src/views/settings/VthConfigurationPage.vue`
- `src/views/settings/tabs/WorkflowsTab.vue`
**Acceptance criteria**:
- Settings page shows tabs: Workflows, Leges Rules, Beschikking Templates, Inspection Checklists, DSO Settings
- Workflows tab: list all templates, view/activate/deactivate versions
- Each tab delegates to child component

- [ ] Create VthConfigurationPage with tab navigation
- [ ] Create WorkflowsTab: list Omgevingsvergunning, Toezichtzaak, Handhavingszaak workflows
- [ ] For each workflow: show version, active status, "Activate/Deactivate" buttons, "View" link (preview diagram), "Download" (JSON backup)
- [ ] Link to Leges Rules, Beschikking Templates, Inspection Checklists tabs (implemented in Tasks 5, 7, 10)
- [ ] Test navigation between tabs
- [ ] Test workflow activation/deactivation

### Task 18: DSO Settings Configuration
**Spec ref**: REQ-VTH-008
**Files**:
- `src/views/settings/tabs/DsoSettingsTab.vue`
**Acceptance criteria**:
- Admin can configure: enabled/disabled, OpenConnector endpoint, deadline warning thresholds, template selections for Verleend/Geweigerd
- Settings are persisted to SettingsService

- [ ] Create DsoSettingsTab with fields:
  - Checkbox: "Enable DSO Integration"
  - Text: "OpenConnector API endpoint"
  - Number: "Deadline warning (weeks before)" (default 6)
  - Number: "Critical deadline warning (weeks before)" (default 2)
  - Dropdown: "Beschikking template for Verleend decision"
  - Dropdown: "Beschikking template for Geweigerd decision"
- [ ] On save: call SettingsService to persist
- [ ] Test field validation (numbers ≥0, endpoint is valid URL)
- [ ] Test persistence and reload

---

## 8. Seed Data and Installation

### Task 19: Create and Import VTH Seed Data
**Spec ref**: Design section "Seed Data"
**Files**:
- `lib/Settings/vth-seed-cases.json`
- `lib/RepairStep/VthSeedDataRepairStep.php`
**Acceptance criteria**:
- 9 seed cases created (3 Omgevingsvergunning, 3 Toezichtzaak, 3 Handhavingszaak) with realistic Dutch data
- Seed cases include: title, zaaktype, status, relevant properties (activiteiten, location, inspector, etc.)
- Idempotent: re-running repair step does not duplicate cases
- Cases are queryable by zaaktype and status

- [ ] Create vth-seed-cases.json with 9 cases:
  - Omgevingsvergunning: office expansion, single-family home, wind turbine
  - Toezichtzaak: building inspection, environmental inspection, safety inspection
  - Handhavingszaak: illegal construction, waste violation, permit breach
- [ ] Each case: realistic Dutch names, addresses, property values
- [ ] Include location references (BAG municipality codes, postal codes)
- [ ] Create `VthSeedDataRepairStep` to load seed data via ObjectService
- [ ] Test seed data creation (all 9 cases exist)
- [ ] Test idempotency (re-running does not duplicate)
- [ ] Test cases are queryable

### Task 20: Repair Step for Template and Configuration Seeding
**Spec ref**: REQ-VTH-001, REQ-VTH-003, REQ-VTH-004
**Files**:
- `lib/RepairStep/VthWorkflowConfigRepairStep.php`
**Acceptance criteria**:
- On app install/upgrade: repair step runs and seeds all templates and configurations
- Idempotent: re-running does not create duplicates
- Templates are loaded before seed cases (so cases can reference them)

- [ ] Create `VthWorkflowConfigRepairStep` implementing IRepairStep
- [ ] In `repair()` method:
  1. Load workflow templates (Omgevingsvergunning, Toezichtzaak, Handhavingszaak)
  2. Register templates in VTHWorkflowService
  3. Seed leges rule sets
  4. Seed beschikking templates
  5. Seed inspection checklists
  6. Seed LHSO matrix
  7. Seed VTH cases (from VthSeedDataRepairStep)
- [ ] Idempotency: check if templates already exist before creating
- [ ] Register step in `appinfo/info.xml`
- [ ] Test repair step execution on fresh install
- [ ] Test idempotency (run repair step twice; no duplicates)

---

## 9. Testing and Validation

### Task 21: Unit Tests for VTH Services
**Spec ref**: All REQ-* sections
**Files**:
- `tests/Unit/Service/LegesCalculationServiceTest.php`
- `tests/Unit/Service/BeschikkingGenerationServiceTest.php`
- `tests/Unit/Service/LhsoLookupServiceTest.php`
- `tests/Unit/Service/DsoIntakeServiceTest.php`
- `tests/Unit/Service/MobileInspectionServiceTest.php`
**Acceptance criteria**:
- Unit tests cover main service methods and edge cases
- All tests pass (100% of assertions)

- [ ] Write LegesCalculationServiceTest:
  - Test fee calculation with modifiers
  - Test verrekening offset logic
  - Test refund calculations
  - Test rule versioning
- [ ] Write BeschikkingGenerationServiceTest:
  - Test template merge field substitution
  - Test required field validation
  - Test template versioning
- [ ] Write LhsoLookupServiceTest:
  - Test all 16 matrix lookups
  - Test invalid inputs
- [ ] Write DsoIntakeServiceTest:
  - Test STAM 2.0 payload mapping
  - Test BRP/organization reference resolution
  - Test bijlagen attachment
- [ ] Write MobileInspectionServiceTest:
  - Test photo upload and Nextcloud storage
  - Test GPS capture and fallback
  - Test validation (required items/photos)
- [ ] All tests pass

### Task 22: Integration Tests for Workflow Transitions
**Spec ref**: REQ-VTH-001, REQ-VTH-002, REQ-VTH-003
**Files**:
- `tests/Integration/WorkflowTransitionTest.php`
**Acceptance criteria**:
- Test complete workflow paths (Omgevingsvergunning intake → beschikking → verleend)
- Test guard validation (e.g., advice required before transition)
- Test notifications are sent on transitions

- [ ] Test Omgevingsvergunning workflow:
  1. Create case → status = Aanvraag ontvangen
  2. Move to In behandeling → task created
  3. Request advies → transitions to Advies aangevraagd
  4. Advies received → transitions to Beschikking opgesteld
  5. Generate beschikking → transitions to Verzonden
  6. Publish → transitions to Verleend
- [ ] Test guard validation (e.g., cannot move to Beschikking before all advies received)
- [ ] Test notifications sent at each transition
- [ ] Test Toezichtzaak inspection flow
- [ ] Test Handhavingszaak LHSO classification and intervention

### Task 23: End-to-End DSO Integration Test
**Spec ref**: REQ-VTH-006
**Files**:
- `tests/Integration/DsoIntegrationTest.php`
**Acceptance criteria**:
- Test DSO verzoek → case creation → status pushback workflow
- Mock OpenConnector events and verify Procest response

- [ ] Create mock DSO verzoek payload (STAM 2.0 format)
- [ ] Simulate ObjectCreatedEvent on vergunningaanvraag
- [ ] Verify case is created with correct zaaktype and data
- [ ] Transition case through workflow (In behandeling → Beschikking opgesteld → Verleend)
- [ ] Verify VergunningStatusChangedEvent is dispatched for each transition
- [ ] Verify event payload includes vergunningaanvraagRef and new status
- [ ] Test complete round-trip

---

## 10. Documentation and Code Quality

### Task 24: Deduplication Check
**Spec ref**: Design section "Reuse Analysis"
**Files**: (internal analysis, no code)
**Acceptance criteria**:
- Verify no overlap with existing services (ObjectService, InspectionChecklist from vth-module, adviceRequest, etc.)
- Document all reused components

- [ ] Grep codebase for similar leges/fee calculation logic (none expected; unique to VTH)
- [ ] Verify BeschikkingGenerationService doesn't duplicate existing document generation (none expected)
- [ ] Verify LhsoLookupService is simple wrapper on vth-module lhsMatrixCell (expected reuse)
- [ ] Verify MobileInspectionService is consumer of vth-module InspectionChecklist (expected reuse)
- [ ] Document all reused components in design.md "Reuse Analysis" section
- [ ] Confirm no unwanted duplication found

### Task 25: Code Style and @spec Tags
**Spec ref**: ADR-003 spec traceability
**Files**: All new classes
**Acceptance criteria**:
- All public methods have @spec PHPDoc tags linking to this spec
- Code follows project style guide (3-layer architecture, DI, etc.)
- No custom entity mappers for domain data (use ObjectService)

- [ ] Add file-level @spec docblock to each new class
- [ ] Add method-level @spec tags to all public methods (link to REQ-* sections)
- [ ] Example: `@spec openspec/changes/vth-workflow-configuration/specs.md#req-vth-001-a`
- [ ] Verify no custom mappers are created for domain data (use ObjectService + OpenRegister)
- [ ] Review for architectural compliance (Controller → Service → Mapper)
- [ ] Run linter/style checker (if project has one)

### Task 26: Update CLAUDE.md with VTH Configuration Context
**Spec ref**: Project documentation
**Files**: `.CLAUDE.md` (or `.claude-context.md`)
**Acceptance criteria**:
- Document VTH configuration architecture for future developers
- Include workflow template structure, leges calculation logic, DSO integration pattern

- [ ] Document VTH workflow template structure (JSON schema)
- [ ] Document leges calculation algorithm (base fee + modifiers + verrekening)
- [ ] Document DSO integration pattern (event-driven, status pushback)
- [ ] Document mobile inspection workflow
- [ ] Add links to relevant specs (this change, vth-module, DSO spec)
- [ ] Update once code is stable

---

## Checklist Summary

- [ ] **Workflow Templates** (Tasks 1–3): All 3 VTH workflow templates created and tested
- [ ] **Leges Calculation** (Tasks 4–5): Fee calculation engine and admin UI complete
- [ ] **Beschikking Generation** (Tasks 6–7): Permit generation service and template UI complete
- [ ] **Mobile Inspection** (Tasks 8–10): Mobile-optimized checklist completion workflow
- [ ] **LHSO Classification** (Tasks 11–13): Enforcement matrix and classification UI
- [ ] **DSO Integration** (Tasks 14–16): Verzoek intake, case creation, status pushback, deadline tracking
- [ ] **Admin Configuration** (Tasks 17–18): Settings pages for all VTH components
- [ ] **Seed Data** (Tasks 19–20): 9 seed cases and configuration seeding via repair steps
- [ ] **Testing** (Tasks 21–23): Unit, integration, and E2E tests for all major features
- [ ] **Code Quality** (Tasks 24–26): Deduplication check, @spec tags, documentation

---

**Completion Criteria**: All tasks are marked complete (✓). All acceptance criteria are met. Repair step runs successfully on install. Seed data loads without duplicates. All 9 seed cases are queryable. Workflows can be activated and used for case management. DSO integration tested end-to-end.
