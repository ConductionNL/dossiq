# Proposal: vth-module

## Why

VTH (Vergunningen, Toezicht, Handhaving) is the single highest-value functional domain for Dutch municipalities adopting Procest. 29% of analysed VTH tenders require an integrated permits/supervision/enforcement module, and stock case-management apps do not cover the cross-domain inspection and sanction workflows that a VTH-volwaardige module needs. This change introduces the V1 tier of the Procest VTH module: a DSO/Omgevingsloket intake service stub, configurable inspection checklists, advice management workflow, and the LHS (Landelijke Handhavingsstrategie) enforcement matrix as data, so downstream changes (mobiel-inspectie, legesberekening) can hook into a real VTH zaaktype stack.

## What Changes

1. VTH case-type templates: omgevingsvergunning, toezichtzaak, handhavingszaak with full GEMMA-compatible status/property/document/role configuration.
2. Inspection checklist schemas and configuration/completion UI hooks consumable by mobiel-inspectie.
3. DSO intake service stub mapping inbound vergunningaanvragen to Procest cases.
4. Advice management service for requesting and tracking specialist advice (interne/externe adviseur).
5. LHS enforcement matrix data and lookup utility (V2 foundation).
6. New OpenRegister schemas in `procest_register.json`: `inspectionChecklist`, `checklistItem`, `inspectionResult`, `adviceRequest`, `lhsMatrixCell`.

## Impact

- **Affected projects**: procest (primary), openconnector (DSO connector), openregister (schemas).
- **Code surface**: new service classes, Vue admin and detail components, register schemas, route additions, repair step for template seeding.
- **Dependencies**: OpenConnector (DSO), OpenRegister, Docudesk (optional for besluit redaction), mobiel-inspectie (consumer), legesberekening (consumer).
- **Standards**: GEMMA VTH-referentiecomponenten (VTH001-VTH119), Omgevingswet/DSO STAM 2.0, LHS 1.7.



## Design

# Design: vth-module

## Architecture

The VTH module sits as a vertical domain on top of the generic Procest case engine. It does NOT fork case-management; it composes existing primitives (case, statusType, propertyDefinition, role) into VTH-shaped templates and adds VTH-specific services for intake, checklists, advice, and enforcement.

### Service Layout

- `VTHTemplateService` — loads `lib/Settings/templates/vth-*.json` template files and activates them as zaaktypes in OpenRegister (parallels the WOO template-library pattern).
- `DSOIntakeService` — receives a STAM 2.0 payload (vergunningaanvraag) from OpenConnector and maps it onto an `omgevingsvergunning` case with linked initiator, bouwlocatie, activiteiten, and uploaded documents.
- `InspectionChecklistService` — CRUD on `inspectionChecklist` (admin) and per-case completion via `inspectionResult` records; exposes endpoints consumed by mobiel-inspectie.
- `AdviceService` — `requestAdvice()`, `submitAdvice()`, `cancelAdvice()`; each `adviceRequest` is linked to a case, has an `adviseur` user/group, deadline, status (open/reminded/received/overdue/cancelled), and feeds into the case timeline.
- `LhsLookupService` — pure lookup on the LHS 4x4 matrix (Beoordeling gedrag × Mogelijke gevolgen) returning the recommended interventieladder step.

### Data Model (OpenRegister Schemas, added to procest_register.json)

- `inspectionChecklist` — name, version, caseTypeRef, items[ref], active, validFrom.
- `checklistItem` — question, type (boolean/enum/text/photo), required, weight, parent (nesting).
- `inspectionResult` — case ref, checklist ref, completedBy, completedAt, answers[{itemRef, value, photoRef}].
- `adviceRequest` — case ref, requestedBy, adviseur, deadline, status, vraag, adviesText, addedToFile.
- `lhsMatrixCell` — gedragRow, gevolgColumn, interventieStep, description.

### API Surface (V1)

- `POST /api/vth/templates/{slug}/activate` — activate VTH template into the active register.
- `POST /api/vth/dso/intake` — DSO callback endpoint (signed payload).
- `GET/POST /api/vth/checklists` — admin CRUD.
- `POST /api/vth/cases/{id}/inspection-result` — submit checklist completion.
- `POST /api/vth/cases/{id}/advice-requests` — create advice request.
- `GET /api/vth/lhs/lookup?gedrag=X&gevolg=Y` — LHS lookup.

## Dependencies

- OpenConnector for DSO callback signature validation and inbound routing.
- OpenRegister for all data storage and schema validation.
- Existing Procest case engine for status transitions, timeline, deadlines.
- Future: mobiel-inspectie (checklist completion on tablet), legesberekening (fee on omgevingsvergunning), docudesk (besluit anonymisering).

## Out of Scope (V2+)

- Full LHS-driven sanctiebesluit generator.
- Automatic koppeling met BAG/BGT for objectreferentie verrijking.
- DSO outbound (publicatie kennisgeving) — handled by separate change.
- 4-ogen accordering op handhavingsbesluit.



## Tasks

# Tasks: vth-module

## 1. Templates and Schemas

### Task 1: Add VTH register schemas
- **spec_ref**: `openspec/specs/vth-module/spec.md#req-vth-08-vth-case-type-templates`
- **files**: `lib/Settings/procest_register.json`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN repair step runs THEN schemas `inspectionChecklist`, `checklistItem`, `inspectionResult`, `adviceRequest`, `lhsMatrixCell` exist
  - All schemas validate as OpenAPI 3.0.0 + `x-openregister`
- [ ] Add 5 new schemas to procest_register.json
- [ ] Update repair step to register schemas

### Task 2: Ship VTH zaaktype templates
- **spec_ref**: `openspec/specs/vth-module/spec.md#req-vth-08-vth-case-type-templates`
- **files**: `lib/Settings/templates/vth-omgevingsvergunning.json`, `vth-toezichtzaak.json`, `vth-handhavingszaak.json`
- **acceptance_criteria**:
  - GIVEN admin activates "Omgevingsvergunning" WHEN template applied THEN status flow, property defs, document types, role types created
  - Each template has version metadata and is idempotent on re-activation
- [ ] Author 3 template JSON files
- [ ] Register templates in `VTHTemplateService`

## 2. DSO Intake

### Task 3: Create DSOIntakeService
- **spec_ref**: `openspec/specs/vth-module/spec.md#req-vth-01-dso-omgevingsloket-integration`
- **files**: `lib/Service/DSOIntakeService.php`, `lib/Controller/DSOIntakeController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN signed STAM 2.0 payload WHEN POST /api/vth/dso/intake THEN omgevingsvergunning case created with mapped fields and documents
  - GIVEN invalid signature WHEN POST THEN 401 returned and audit log entry written
- [ ] Implement DSOIntakeService.map(), .createCase()
- [ ] Implement DSOIntakeController.intake()
- [ ] Register route

## 3. Inspection Checklists

### Task 4: Create InspectionChecklistService
- **spec_ref**: `openspec/specs/vth-module/spec.md#req-vth-03-inspection-checklists`
- **files**: `lib/Service/InspectionChecklistService.php`, `lib/Controller/InspectionChecklistController.php`
- **acceptance_criteria**:
  - GIVEN 12-item checklist WHEN 11 items completed THEN progress is 11/12 and case cannot advance
  - GIVEN niet-conform answer requiring photo WHEN no photo uploaded THEN save blocked with validation error
- [ ] Implement CRUD on checklists
- [ ] Implement submitResult() with required-photo validation

### Task 5: Build checklist admin UI
- **spec_ref**: `openspec/specs/vth-module/spec.md#req-vth-03-inspection-checklists`
- **files**: `src/views/settings/tabs/ChecklistsTab.vue`, `src/components/InspectionChecklistEditor.vue`
- **acceptance_criteria**:
  - Admin can create/edit/delete checklists with nested items
  - Each item supports type (boolean/enum/text/photo), required, weight
- [ ] Add ChecklistsTab to settings
- [ ] Build editor component

## 4. Advice Management

### Task 6: Create AdviceService
- **spec_ref**: `openspec/specs/vth-module/spec.md#req-vth-06-advice-management-advies`
- **files**: `lib/Service/AdviceService.php`, `lib/Controller/AdviceController.php`
- **acceptance_criteria**:
  - GIVEN behandelaar requests advice with deadline +14 days WHEN created THEN adviseur receives notification and timeline entry appears
  - GIVEN deadline passed WHEN nightly job runs THEN status becomes "overdue" and reminder sent
- [ ] Implement requestAdvice, submitAdvice, cancelAdvice
- [ ] Add overdue cronjob

### Task 7: Build advice request UI
- **spec_ref**: `openspec/specs/vth-module/spec.md#req-vth-06-advice-management-advies`
- **files**: `src/views/cases/components/AdviceRequestPanel.vue`
- **acceptance_criteria**:
  - Panel renders on VTH case detail showing open/received/overdue advice
  - "Request advice" dialog with adviseur picker, deadline, vraag textarea
- [ ] Build AdviceRequestPanel component
- [ ] Wire into VTH case detail tab

## 5. LHS Enforcement Matrix

### Task 8: Seed LHS matrix and lookup
- **spec_ref**: `openspec/specs/vth-module/spec.md#req-vth-04-enforcement-strategies-handhaving`
- **files**: `lib/Settings/lhs_matrix_seed.json`, `lib/Service/LhsLookupService.php`, `lib/Controller/LhsController.php`
- **acceptance_criteria**:
  - GIVEN gedrag=B and gevolg=2 WHEN GET /api/vth/lhs/lookup?gedrag=B&gevolg=2 THEN returns interventieStep "Bestuurlijke waarschuwing" with description
  - All 16 LHS cells seeded on install
- [ ] Seed 16-cell matrix as repair step data
- [ ] Implement LhsLookupService.lookup()
- [ ] Register route and controller