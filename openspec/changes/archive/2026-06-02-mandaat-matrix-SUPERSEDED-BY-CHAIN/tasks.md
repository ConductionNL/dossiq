# Tasks: Mandaat-matrix voor zaak-gestuurde besluitvorming

Implementation tasks for mandate-matrix capability, covering data model, authorization engine, escalation, UI, and compliance features.

---

## 1. Data Model and Schemas

### Task 1: Create OpenRegister Schemas
**Spec ref**: Design section "Data Model"
**Files**:
- `openspec/schema/mandaat-matrix.schema.json`
- Update `procest_register.json` to include 6 new schemas
**Acceptance criteria**:
- All 6 schemas defined: MandateringsBesluit, Mandaat, OrganisatieRol, MedewerkerRolToewijzing, MandaatGebruik, MandaatEscalatie
- Schema validation passes OpenAPI 3.0 + x-openregister
- Relations defined: Mandaat → MandateringsBesluit, Mandaat → OrganisatieRol, etc.

- [ ] Define MandateringsBesluit schema with all fields (besluitNummer, besluitNaam, status, dates, etc.)
- [ ] Define Mandaat schema with voorwaarden (JSON type for plafond, subdelegatie, etc.)
- [ ] Define OrganisatieRol schema with hierarchical parentRolId
- [ ] Define MedewerkerRolToewijzing schema with toewijzingType enum
- [ ] Define MandaatGebruik schema with JSON snapshot fields (rolOpMomentVanBesluit, gebruikteVoorwaarden)
- [ ] Define MandaatEscalatie schema with escalatieReden enum
- [ ] Validate schema JSON against OpenAPI spec
- [ ] Create migration/upgrade step in appinfo/info.xml to register schemas on install
- [ ] Test schema creation (register returns schema UUID)

### Task 2: Seed Data — OrganisatieRol, MedewerkerRolToewijzing, MandateringsBesluit, Mandaat
**Spec ref**: Design section "Seed Data"
**Files**:
- `lib/Settings/mandate-matrix-seed.json`
- `lib/RepairStep/MandaatMatrixSeedRepairStep.php`
**Acceptance criteria**:
- 7 OrganisatieRol records created (3 levels VV + 2 levels HAN + 2 support)
- 5 MedewerkerRolToewijzing records (including 1 waarnemer)
- 2 MandateringsBesluit records (current + predecessor)
- 4 Mandaat records seeded with proper references
- Idempotent: re-running does not duplicate

- [ ] Create mandate-matrix-seed.json with seed data (RoleIDs will be populated by repair step)
- [ ] Create MandaatMatrixSeedRepairStep.php
- [ ] In repair() method: create OrganisatieRol, then MedewerkerRolToewijzing, then MandateringsBesluit, then Mandaat
- [ ] Use ObjectService to create objects; capture returned UUIDs
- [ ] Update JSON structure to reference created UUIDs (iterative seeding)
- [ ] Register repair step in appinfo/info.xml
- [ ] Test seed data creation (all records exist with correct references)
- [ ] Test idempotency (run twice; no duplicates)

---

## 2. Authorization Engine

### Task 3: Implement MandaatCheckService
**Spec ref**: REQ-MANDAAT-002, REQ-MANDAAT-003, REQ-MANDAAT-004
**Files**:
- `lib/Service/MandaatCheckService.php`
- `lib/Service/OrganisatieRolService.php`
**Acceptance criteria**:
- `isAuthorized(userId, decisionType, caseId)` → {authorized, mandaatId?, escalatieId?, reden?}
- Role resolution includes waarnemer lookups (active on decision date)
- Condition evaluation: plafond, subdelegatie
- All tests pass

- [ ] Implement `MandaatCheckService.isAuthorized(userId, decisionType, caseId)`
- [ ] Load applicable Mandaat records for decisionType
- [ ] Call `resolveUserRole(userId, date=today)` → returns OrganisatieRol + waarnemer flags
- [ ] Check if role holds mandaat: search Mandaat where gemandateerdeRol = userRole
- [ ] If not found: return {authorized: false, reden: "niet_bevoegd"}
- [ ] If found: call `evaluateConditions(mandaat, caseProperties)` → {passed, failedConditions}
- [ ] If conditions fail: return {authorized: false, reden: "plafond_overschreden" | "subdelegatie_niet_toegestaan"}
- [ ] If authorized: return {authorized: true, mandaatId: mandaat.uuid}
- [ ] Implement `resolveUserRole(userId, date)`:
  - Lookup MedewerkerRolToewijzing for userId with toewijzingVanaf ≤ date ≤ toewijzingTotEnMet
  - If toewijzingType="primair": return role
  - If toewijzingType="waarnemer": also check, apply waarnemer flag
  - If multiple active: return primary + waarnemer list
- [ ] Implement `evaluateConditions(mandaat, caseProperties)`:
  - Parse mandaat.voorwaarden JSON (plafond_bedrag, subdelegatie_toegestaan, etc.)
  - Match case properties (bouwsom, etc.)
  - Return {passed: bool, failedConditions: []}
- [ ] Implement `getApplicableMandaten(decisionType, caseType, date)` → [Mandaat]
  - Query Mandaat where decisionType matches AND geldigVanaf ≤ date ≤ geldigTotEnMet
  - Return all matching
- [ ] Test with multiple scenarios: role holds mandate, role doesn't, plafond exceeded, subdelegatie blocked, waarnemer
- [ ] Unit test with various dates (effective dating scenarios)

### Task 4: ABAC Policy Engine Integration
**Spec ref**: REQ-MANDAAT-002 (integration detail)
**Files**:
- `lib/Service/AbacPolicyService.php` (wrapper)
**Acceptance criteria**:
- MandaatCheckService delegates condition evaluation to policy engine
- Policy engine receives fact set (user, mandate, case properties)
- Engine evaluates conditions atomically

- [ ] Create `AbacPolicyService` wrapper around openregister policy-engine
- [ ] Implement `evaluatePolicy(policyName, factSet)` → {allowed: bool, violations: []}
- [ ] Integrate with MandaatCheckService: call policy engine for condition evaluation
- [ ] Pass fact set: {userId, rolId, mandaatId, caseType, caseProperties, decisionType}
- [ ] Policy engine evaluates: plafond rule, subdelegatie rule, etc.
- [ ] Test policy evaluation with sample policies

---

## 3. Escalation Engine

### Task 5: Implement MandaatEscalatieService
**Spec ref**: REQ-MANDAAT-003, REQ-MANDAAT-008
**Files**:
- `lib/Service/MandaatEscalatieService.php`
**Acceptance criteria**:
- `createEscalatie(zaakId, decisionType, initiatorId, reden)` → MandaatEscalatie UUID
- Escalation path resolution: find next-higher mandaat and current role holder(s)
- Auto-rerouting on personnel changes
- Notifications sent

- [ ] Implement `createEscalatie(zaakId, decisionType, initiatorId, reden)`:
  - Validate inputs (zaakId, decisionType, initiatorId exist)
  - Call `resolveEscalatiePath(decisionType, reden)` → {escalatiePadEindigtBij, nextMandaatId}
  - Create MandaatEscalatie record with status="open"
  - Send notification to escalatiePadEindigtBij (via NotificationService)
  - Update case status to "Wacht op besluit hoger mandaat" (if workflow supports)
  - Return escalatieId
- [ ] Implement `resolveEscalatiePath(decisionType, reden)`:
  - Query all Mandaat records for this decisionType ordered by plafond DESC
  - For each, check if it has higher authority than current user's mandate
  - Return first applicable mandaat and its gemandateerdeRol
  - Lookup current MedewerkerRolToewijzing for that role → get user ID
  - Handle multiple role holders (return primary or all?)
- [ ] Implement `autoRerouteOnPersonnelChange(oldUserId, newUserId, rolId)`:
  - Query all open MandaatEscalatie where escalatiePadEindigtBij = oldUserId AND role = rolId
  - Update escalatiePadEindigtBij = newUserId
  - Send notifications to old and new recipients
- [ ] Implement notification sending (integrate with NotificationService or n8n)
- [ ] Test escalation creation with various reden values
- [ ] Test escalation path resolution (next-higher role identified)
- [ ] Test personnel change rerouting

---

## 4. Escalation Approval/Rejection

### Task 6: Implement Escalation Approval and Rejection
**Spec ref**: REQ-MANDAAT-008
**Files**:
- `lib/Controller/MandaatEscalatieController.php`
- `lib/Service/EscalatieApprovalService.php`
**Acceptance criteria**:
- `POST /api/mandate/escalatie/{escalatieId}/approve` → decision executes, MandaatGebruik logged
- `POST /api/mandate/escalatie/{escalatieId}/reject` → decision cancelled, case reverts
- Audit trail records approval/rejection

- [ ] Create `EscalatieApprovalService.approveEscalatie(escalatieId, mandaathouderUserId)`:
  - Validate escalation is open and current user can approve
  - Execute the underlying decision (via case/workflow API)
  - Create MandaatGebruik log with mandaathouderUserId
  - Update escalation status → "goedgekeurd"
  - Send notification to original initiator: "Decision approved by [mandaathouder]"
  - Update case status back to normal workflow (if applicable)
- [ ] Create `rejectEscalatie(escalatieId, reason)`:
  - Validate escalation is open
  - Do NOT execute decision
  - Update escalation status → "afgewezen"
  - Store rejection reason
  - Revert case to prior status (if changed)
  - Send notification to initiator: "Decision rejected: [reason]"
- [ ] Create REST endpoints:
  - `POST /api/mandate/escalatie/{escalatieId}/approve` → calls approveEscalatie
  - `POST /api/mandate/escalatie/{escalatieId}/reject` → payload: {reason} → calls rejectEscalatie
  - `GET /api/mandate/escalatie` (list open escalaties for logged-in user)
  - `GET /api/mandate/escalatie/{escalatieId}` (detail view)
- [ ] Test approval flow end-to-end
- [ ] Test rejection and re-submit
- [ ] Verify case status transitions correctly

---

## 5. Decidesk Integration and Import

### Task 7: Implement Decidesk Import Service
**Spec ref**: REQ-MANDAAT-001
**Files**:
- `lib/Service/DecideskImportService.php`
- `lib/Controller/MandaatImportController.php`
**Acceptance criteria**:
- `importFromDecidesk(decidesk_uuid)` → creates MandateringsBesluit + Mandaat records
- Excel/CSV table parsing: extract mandaatNummer, omschrijving, rolNaam, plafond, subdelegatie
- Validation: all referenced roles exist
- Diff view: NEW/CHANGED/REMOVED mandaten vs. prior version

- [ ] Implement `DecideskImportService.importFromDecidesk(decidesk_uuid)`:
  - Call decidesk API to retrieve besluit details + attachment
  - Extract besluit metadata (besluitNummer, besluitDatum, besluitOrgaan, etc.)
  - Download attachment (PDF or Excel)
  - If PDF: attempt OCR or manual parsing (depending on format)
  - If Excel: parse via PhpSpreadsheet library
  - Extract table rows: {mandaatNummer, omschrijving, rolNaam, plafondBedrag, subdelegatie, ...}
  - Validate: all rolNaam values exist in OrganisatieRol registry (error if not)
  - Create MandateringsBesluit record with status="concept"
  - For each row: create Mandaat record
  - Generate diff vs. prior MandateringsBesluit (if any)
  - Return {mandateringsBesluitId, totalMandaten, newCount, changedCount, removedCount, diff}
- [ ] Implement table parsing:
  - Support Excel (.xlsx) and CSV formats
  - Expected columns: Nummer, Omschrijving, Rol, PlafondBedrag, Subdelegatie, WettelijkeGrondslag
  - Optional columns: Beschrijving, Opmerkingen
  - Skip empty rows and header rows
  - Handle merged cells in Excel (flatten to single value)
- [ ] Implement diff generation:
  - Compare new mandaten vs. prior version by mandaatNummer
  - Categorize: NEW (not in prior), CHANGED (fields differ), REMOVED (in prior but not new), UNCHANGED
  - Return detailed diff with field-level changes
- [ ] Implement approval flow:
  - After diff review, user clicks "Approve Import"
  - MandateringsBesluit status → "vastgesteld"
  - All Mandaat records status → "active"
  - Prior MandateringsBesluit (if any) status → "vervallen"; set vervalDatum = yesterday
- [ ] Create REST endpoint:
  - `POST /api/mandate/import` → payload: {decidesk_uuid} → returns import preview (diff)
  - `POST /api/mandate/import/{importId}/approve` → finalizes import
- [ ] Test import with sample Excel file
- [ ] Test validation (missing role → error)
- [ ] Test diff generation
- [ ] Test idempotency (import twice; second time is no-op or error)

---

## 6. Authorization Check Integration into Case Decisions

### Task 8: Integrate MandaatCheckService into Decision Action Flow
**Spec ref**: REQ-MANDAAT-012
**Files**:
- `lib/Listener/CaseDecisionActionListener.php`
- Update existing decision-handling code
**Acceptance criteria**:
- Before any case decision is executed, MandaatCheckService.isAuthorized() is called
- If not authorized: decision is blocked, escalation offered
- If authorized: decision proceeds, MandaatGebruik logged
- No impact on decisions without mandate requirement

- [ ] Create `CaseDecisionActionListener` listening to case decision events
- [ ] In listener: intercept decision action BEFORE execution
- [ ] Extract: userId, decisionType, caseId
- [ ] Call `MandaatCheckService.isAuthorized(userId, decisionType, caseId)`
- [ ] If {authorized: false}: dispatch EscalatieCreatedEvent with escalation details
  - Return error response to UI: "Not authorized. [Escalation offered]"
  - Prevent decision execution
- [ ] If {authorized: true}:
  - Allow decision to proceed
  - Capture mandaatId from response
  - Create MandaatGebruik log entry after decision completes (via post-execution hook)
- [ ] Hook into existing decision execution pipeline (identify where decisions are executed in procest)
- [ ] Register listener in appinfo/info.xml
- [ ] Test decision with mandate → proceeds
- [ ] Test decision without mandate → escalates
- [ ] Test decision with plafond exceeded → escalates

---

## 7. MandaatGebruik Logging

### Task 9: Implement MandaatGebruik Immutable Logging
**Spec ref**: REQ-MANDAAT-007
**Files**:
- `lib/Service/MandaatGebruikService.php`
**Acceptance criteria**:
- MandaatGebruik entry created immediately after each authorized decision
- Snapshot of role, mandate, conditions captured atomically
- Entry is immutable (no edit/delete allowed)
- Audit trail queryable per zaak

- [ ] Implement `MandaatGebruikService.logMandaatGebruik(zaakId, decisionId, mandaatId, userId, conditions)`:
  - Capture current timestamp
  - Resolve user's OrganisatieRol (snapshot)
  - Resolve mandaat details (snapshot)
  - Create MandaatGebruik record with all snapshots + decision metadata
  - Lock record (set locked: true in OpenRegister)
- [ ] Implement immutability:
  - Set readonly flag on MandaatGebruik schema in OpenRegister
  - Prevent update via API (return 403 Forbidden)
  - Allow retrieval and export only
- [ ] Implement audit trail export:
  - `getDecisionAuditTrail(zaakId)` → list of all MandaatGebruik entries
  - `getDecisionByMandaat(mandaatId, dateRange)` → all decisions using this mandate
- [ ] Test logging on authorized decision
- [ ] Test immutability (attempt to update → error)
- [ ] Test audit trail retrieval

---

## 8. Admin UI — Mandate Matrix and Role Management

### Task 10: Create Mandate Matrix Admin Panel
**Spec ref**: REQ-MANDAAT-011
**Files**:
- `src/views/settings/MandaatMatrixSettings.vue`
- `src/components/MandaatMatrixTable.vue`
- `src/components/MandaatEditor.vue`
- `src/components/OrganisatieRolManager.vue`
**Acceptance criteria**:
- Admin can view/edit all Mandaat records per MandateringsBesluit
- Admin can manage OrganisatieRol hierarchy
- Admin can manage MedewerkerRolToewijzing (person-to-role assignments)
- Validation: role exists before mandate assignment, no orphaned records

- [ ] Create MandaatMatrixSettings page with tabs: Besluiten | Rollen | Toewijzingen | Import
- [ ] In Besluiten tab: table of MandateringsBesluit with columns: #, Naam, Status, InWerkingtreding, VervaldatumEdit
  - Click row → detail view with all Mandaat records
  - Edit button → MandaatEditor component
  - Import button → import workflow (Task 7)
- [ ] Build MandaatEditor component:
  - Fields: mandaatNummer, omschrijving, bevoegdheidType, wettelijkeGrondslag
  - Voorwaarden editor (JSON): plafond_bedrag, subdelegatie_toegestaan, etc.
  - Validity date pickers
  - Role selector (dropdown of available OrganisatieRol)
  - Save/Cancel buttons
- [ ] Build OrganisatieRolManager:
  - Hierarchical tree view of roles (parent-child relationships)
  - Add/edit/delete role
  - Fields: name, type, parentRole, afdeling, team, mandaatNiveau
  - Cannot delete if referenced by Mandaat or active ToewijzingIn Toewijzingen tab: table of MedewerkerRolToewijzing
  - Columns: Person | Role | Type | VanafTotEnMet | Edit/End
  - "Add assignment" button → dialog: select person, role, startdate, type
  - "End assignment" button → set toewijzingTotEnMet = today (or custom date)
  - Waarnemer assignments highlighted differently (e.g., italic)
- [ ] Test CRUD on all three entity types
- [ ] Test validation (cannot create role without parent if required, etc.)
- [ ] Test waarnemer assignment workflow

---

## 9. User-Facing UI — Bevoegdheden View

### Task 11: Create User Bevoegdheden Dashboard
**Spec ref**: REQ-MANDAAT-006
**Files**:
- `src/views/cases/components/BevoegdhedenPanel.vue`
- `src/components/MandaatMatrixWidget.vue`
**Acceptance criteria**:
- On case detail, user can click "Toon bevoegdheden" → panel shows their authority for this case
- Matrix filtered by user's role(s)
- Decision-type filter: "What can I do?"
- Waarnemer relationships clearly indicated
- Link to mandateringsbesluit reference

- [ ] Create BevoegdhedenPanel component (side panel or modal)
- [ ] Load applicable Mandaat records for this caseType, filtered by user's current roles
- [ ] Table columns: Mandaat # | Omschrijving | Bevoegdheidtype | Plafond | Subdelegatie | Geldend v/t | Details
- [ ] On row click → expand detail panel:
  - Full mandate description
  - Wettelijke grondslag link
  - Current role holders (list of people in this role today)
  - Waarnemer note (if user is acting as substitute)
  - MandateringsBesluit source: "CR 2026-001, effective 2026-01-01"
- [ ] Filter "What can I do?" → shows only decision types in current zaaktype that user can unilaterally execute
- [ ] Test on case detail page
- [ ] Test with different user roles (including waarnemer)
- [ ] Test filter functionality

---

## 10. Effective Dating and Temporal Queries

### Task 12: Implement Temporal Mandate Queries
**Spec ref**: REQ-MANDAAT-005
**Files**:
- `lib/Service/MandaatQueryService.php` (extend MandaatCheckService)
**Acceptance criteria**:
- `getMandaatAsOf(mandaatId, date)` → Mandaat or prior version
- Authorization checks use correct mandate version for decision date
- Audit trail shows which version was used
- Future-dated mandate suggestions available ("Schedule for [date] to use new mandate")

- [ ] Implement `getMandaatAsOf(mandaatId, date)`:
  - Query Mandaat with mandaatId AND geldigVanaf ≤ date ≤ geldigTotEnMet
  - If exact match: return
  - If date before earliest version: return error or null
  - If date after latest version: return null (mandate not yet effective)
- [ ] Update `isAuthorized()` to accept optional decisionDate parameter (default: today)
- [ ] Pass decisionDate to MandaatQueryService for temporal lookup
- [ ] In MandaatGebruik: record which mandaat version was used (for audit)
- [ ] Implement suggestion service: `suggestFutureDate(mandaatId, decisionProperties)` → "Schedule for 2026-07-01 to use newer mandate with plafond €100K"
- [ ] Update UI: after escalation, suggest future scheduling option
- [ ] Test authorization with past and future dates
- [ ] Test audit trail shows correct version

---

## 11. Conflict of Interest Detection

### Task 13: Implement Belangenconflict Detection
**Spec ref**: REQ-MANDAAT-010
**Files**:
- `lib/Service/ConflictOfInterestService.php`
**Acceptance criteria**:
- Automatic BRP relationship check (user ↔ applicant, family members)
- Manual conflict registration
- Decision blocked if conflict detected; escalation triggered

- [ ] Implement `ConflictOfInterestService.checkConflict(userId, zaakId)`:
  - Extract applicant BSN/details from case
  - Call BRP service: are userId and applicant related? (spouse, child, parent, sibling)
  - Return {conflict: bool, reason: string}
- [ ] Integrate with MandaatCheckService: call conflict check in authorization pipeline
- [ ] If conflict detected: return {authorized: false, reden: "belangenconflict"}
- [ ] Implement manual conflict registration UI:
  - Case detail: button "Register interest conflict"
  - Dialog: textarea for reason
  - Save → set case property belangenconflict = true
  - Trigger escalation
- [ ] Test automatic BRP conflict detection
- [ ] Test manual registration
- [ ] Test decision blocked when conflict exists

---

## 12. Testing and Validation

### Task 14: Unit Tests for MandaatCheckService
**Spec ref**: All REQ-MANDAAT sections
**Files**:
- `tests/Unit/Service/MandaatCheckServiceTest.php`
**Acceptance criteria**:
- Test authorization with various role/mandate combinations
- Test waarnemer authority
- Test plafond evaluation
- Test subdelegatie blocking
- All assertions pass

- [ ] Test `isAuthorized()` with user holding mandate → authorized: true
- [ ] Test user NOT holding mandate → authorized: false, reden: "niet_bevoegd"
- [ ] Test plafond exceeded → authorized: false, reden: "plafond_overschreden"
- [ ] Test subdelegatie not permitted → authorized: false, reden: "subdelegatie_niet_toegestaan"
- [ ] Test waarnemer authority → authorized: true, role snapshot shows waarnemer
- [ ] Test temporal mandate query (different dates) → returns correct version
- [ ] Test multiple role holders → escalation routes to primary
- [ ] All tests passing

### Task 15: Integration Tests for Escalation Workflow
**Spec ref**: REQ-MANDAAT-003, REQ-MANDAAT-008
**Files**:
- `tests/Integration/EscalatieWorkflowTest.php`
**Acceptance criteria**:
- End-to-end escalation: plafond exceeded → escalation created → approved → decision executes
- Escalation rejection → decision cancelled
- Personnel change rerouting

- [ ] Test escalation creation on plafond overshoot
- [ ] Test escalation approval → decision executes + MandaatGebruik logged
- [ ] Test escalation rejection → decision not executed, case status unchanged
- [ ] Test personnel change → escalation rerouted to new role holder
- [ ] Test waarnemer period: during period escalations go to waarnemer, after period to primary
- [ ] All tests passing

### Task 16: Authorization Guard in Case Workflow
**Spec ref**: REQ-MANDAAT-012
**Files**:
- `tests/Integration/CaseDecisionAuthorizationTest.php`
**Acceptance criteria**:
- Case decision action with mandate requirement → authorization checked
- User with mandate → decision proceeds
- User without mandate → decision blocked, escalation offered
- MandaatGebruik logged for authorized decisions

- [ ] Create test case with decision requiring mandate
- [ ] Test authorized user → decision succeeds, MandaatGebruik logged
- [ ] Test unauthorized user → decision blocked, escalation created
- [ ] Test waarnemer → decision authorized with waarnemer flag
- [ ] Test escalation approval path
- [ ] All tests passing

---

## 13. Documentation and Code Quality

### Task 17: Add @spec Tags and Documentation
**Spec ref**: ADR-003, Design section
**Files**: All new classes
**Acceptance criteria**:
- All public methods have @spec docblock tags
- Code follows project architecture (3-layer: Controller → Service → OpenRegister)
- Design documentation updated

- [ ] Add file-level @spec docblock to each service class
- [ ] Add method-level @spec tags (link to REQ-* sections)
- [ ] Example: `@spec openspec/changes/mandaat-matrix/specs.md#req-mandaat-002`
- [ ] Review for architectural compliance (no custom mappers, use ObjectService for CRUD)
- [ ] Run linter/code style checks
- [ ] Update project CLAUDE.md with mandate-matrix context (architecture, decision flow, escalation pattern)

### Task 18: Create Admin Documentation
**Spec ref**: REQ-MANDAAT-001, REQ-MANDAAT-011
**Files**:
- `docs/user/mandate-matrix-admin.md`
**Acceptance criteria**:
- Step-by-step guide for importing mandateringsbesluit from decidesk
- Role hierarchy configuration walkthrough
- Personnel assignment (waarnemer) workflow documented
- Troubleshooting guide (missing roles, validation errors)

- [ ] Document import workflow with screenshots
- [ ] Document role hierarchy setup
- [ ] Document waarnemer assignment for absence coverage
- [ ] Troubleshoot common errors (missing role, validation failure)
- [ ] Provide sample Excel template for mandate table
- [ ] Add FAQ section

---

## Checklist Summary

- [ ] **Data Model** (Tasks 1–2): All schemas created, seed data loaded, idempotent repair step
- [ ] **Authorization Engine** (Tasks 3–4): MandaatCheckService complete, condition evaluation, ABAC integration
- [ ] **Escalation** (Tasks 5–6): Escalation creation, approval/rejection, personnel rerouting
- [ ] **Import** (Task 7): Decidesk integration, table parsing, diff view, approval flow
- [ ] **Case Integration** (Task 8): Authorization guard in decision action pipeline
- [ ] **Audit Trail** (Task 9): MandaatGebruik logging, immutability, export
- [ ] **Admin UI** (Task 10): Mandate matrix editor, role manager, toewijzing manager
- [ ] **User UI** (Task 11): Bevoegdheden dashboard, filter, role holder view
- [ ] **Effective Dating** (Task 12): Temporal mandate queries, version switching, suggestions
- [ ] **Conflict Detection** (Task 13): BRP integration, manual registration, escalation trigger
- [ ] **Testing** (Tasks 14–16): Unit tests, integration tests, authorization guard tests; all passing
- [ ] **Documentation** (Tasks 17–18): @spec tags, CLAUDE.md, admin guide

---

**Completion Criteria**: 
- All tasks marked complete (✓)
- All unit and integration tests passing
- Authorization guard enforced on all case decisions with mandate requirements
- Escalation workflow end-to-end functional
- Decidesk import creates valid mandate records
- Audit trail immutable and queryable per zaak
- Admin can manage mandate matrix and role assignments
- Users see their bevoegdheden and escalation options

---

**Post-Implementation Checklist**:
- [ ] Repair step runs successfully on app install/upgrade
- [ ] Seed data loads without errors (9 total: 7 roles + 5 assignments + 2 besluiten + 4 mandaten)
- [ ] Authorization checks work on test cases (authorized, denied, escalated scenarios)
- [ ] Escalation approval → decision executes with correct MandaatGebruik logging
- [ ] Personnel change → escalation rerouted automatically
- [ ] Audit trail export includes all decisions with mandate snapshots
- [ ] Performance: authorization check completes in <100ms
- [ ] Documentation complete and reviewed
