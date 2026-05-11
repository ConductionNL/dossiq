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
