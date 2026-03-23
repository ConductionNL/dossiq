## 1. VTH Schemas in Register (V1)

- [x] 1.1 Add `inspectieChecklist` schema to procest_register.json with fields: name, caseType (reference), version (integer), status (enum: draft/active/archived), items (array)
- [x] 1.2 Add `checklistItem` schema to procest_register.json with fields: order, label, type (enum: ja_nee_nvt/tekst/getal/foto/meerkeuze), required, fotoRequired, options, helpText
- [x] 1.3 Add `inspectieRapport` schema to procest_register.json with fields: case (reference), checklist (reference), inspector, inspectionDate, location, result (enum: conform/niet_conform/deels_conform), failedItems, items (array), photos (array), remarks, followUpRequired
- [x] 1.4 Add `handhavingsactie` schema to procest_register.json with fields: case (reference), type (enum), ernst (enum), gedrag (enum), interventie, begunstigingstermijn, dwangsomBedrag, dwangsomMaximaal, effectueringsDatum, status (enum: opgelegd/verbeurd/geeffectueerd/ingetrokken)
- [x] 1.5 Add `adviesAanvraag` schema to procest_register.json with fields: case (reference), adviseur, type (enum: intern/extern), onderwerp, deadline, status (enum: aangevraagd/ontvangen/verlopen), adviesDocument, requestedAt, receivedAt

## 2. VTH Case Type Seed Data (V1)

- [x] 2.1 Add seed case type "Omgevingsvergunning Bouwactiviteit" with status types, role types, document types, property definitions, processingDeadline P56D
- [x] 2.2 Add seed case type "Sloopmelding" with status types, processingDeadline P28D
- [x] 2.3 Add seed case type "Toezichtzaak Bouw" with inspection phase status types, role types, document types
- [x] 2.4 Add seed case type "Toezichtzaak Milieu" with status types, role types, property definitions
- [x] 2.5 Add seed case type "Handhavingszaak" with enforcement status types, role types, document types, LHS property definitions
- [x] 2.6 Add seed case type "Invorderingszaak" with status types, property definitions

## 3. Default Inspection Checklists (V1)

- [x] 3.1 Add seed checklist "Bouwtoezicht fase 1 - Fundering" with 4 items linked to Toezichtzaak Bouw
- [x] 3.2 Add seed checklist "Bouwtoezicht fase 2 - Ruwbouw" with 5 items linked to Toezichtzaak Bouw
- [x] 3.3 Add seed checklist "Bouwtoezicht fase 3 - Oplevering" with 6 items linked to Toezichtzaak Bouw

## 4. VTH Workflow Templates (V1)

- [x] 4.1 Create `lib/Settings/vth-templates/` directory for workflow template JSON files
- [x] 4.2 Create Omgevingsvergunning regulier workflow template JSON with 8 steps, guards, and actions
- [x] 4.3 Create Omgevingsvergunning uitgebreid workflow template JSON with additional zienswijze/ontwerp-besluit steps
- [x] 4.4 Create Toezichtzaak Bouw workflow template JSON with 7 steps and inspection checklist guards
- [x] 4.5 Create Handhavingszaak workflow template JSON with 7 steps, LHS guard, and timer-based transitions
- [x] 4.6 Create Sloopmelding workflow template JSON with 4 simple steps
- [x] 4.7 Create Toezichtzaak Milieu workflow template JSON with 5 steps

## 5. VTH Template Library UI (V1)

- [x] 5.1 Create VthTemplateLibrary.vue component with browsable list of VTH workflow templates (name, description, step count, processing time)
- [x] 5.2 Add template preview mode using read-only workflow editor visualization
- [x] 5.3 Integrate VthTemplateLibrary into WorkflowTab.vue with "Importeer VTH sjabloon" button
- [x] 5.4 Implement template import action that loads JSON into workflow store and creates editable copy

## 6. Inspection Pinia Store (V1)

- [x] 6.1 Create `src/store/modules/inspection.js` Pinia store with CRUD for inspectieChecklist via OpenRegister API
- [x] 6.2 Add inspectieRapport creation action with auto-calculated overall result (conform/niet_conform/deels_conform)
- [x] 6.3 Add photo upload action that stores files in Nextcloud case folder and links to checklist items
- [x] 6.4 Add follow-up task creation action when rapport has non-conformities

## 7. Inspection Admin UI (V1)

- [x] 7.1 Create ChecklistAdmin.vue tab component for CaseTypeDetail with CRUD for inspectieChecklist objects
- [x] 7.2 Implement checklist item editor with drag-and-drop reordering, type selector, required/fotoRequired toggles
- [x] 7.3 Implement checklist versioning UI: create new version from active, archive old versions

## 8. Inspection Panel on Case Dashboard (V1)

- [x] 8.1 Create InspectionPanel.vue component showing inspection progress bar and phase list
- [x] 8.2 Implement checklist completion form for filling in inspection items (pass/fail/nvt, comments, photos, measurements)
- [x] 8.3 Implement rapport detail view with expandable checklist item results and linked photos
- [x] 8.4 Integrate InspectionPanel into CaseDetail.vue conditionally for Toezicht case types

## 9. Enforcement Pinia Store (V1)

- [x] 9.1 Create `src/store/modules/enforcement.js` Pinia store with CRUD for handhavingsactie via OpenRegister API
- [x] 9.2 Add LHS matrix lookup action that reads matrix from IAppConfig and returns suggested interventie
- [x] 9.3 Add enforcement status lifecycle actions (opgelegd -> verbeurd -> geeffectueerd/ingetrokken)
- [x] 9.4 Add begunstigingstermijn tracking with follow-up task creation on expiry

## 10. LHS Matrix Admin UI (V1)

- [x] 10.1 Create LhsMatrixAdmin.vue component for Procest Admin > VTH Instellingen with editable 4x4 grid
- [x] 10.2 Add default LHS matrix data stored in IAppConfig on first load
- [x] 10.3 Wire LHS matrix admin into SettingsController for save/load via API

## 11. Enforcement Wizard and Panel (V1)

- [x] 11.1 Create EnforcementWizard.vue 3-step dialog: Classification (ernst/gedrag selectors), Intervention details (dwangsom fields), Vooraankondiging (brief/zienswijzetermijn)
- [x] 11.2 Create EnforcementPanel.vue component showing LHS classification, interventie, enforcement status, dwangsom tracking
- [x] 11.3 Integrate EnforcementPanel and wizard trigger into CaseDetail.vue for Handhaving case types

## 12. Advice Pinia Store (V1)

- [x] 12.1 Create `src/store/modules/advice.js` Pinia store with CRUD for adviesAanvraag via OpenRegister API
- [x] 12.2 Add advice status lifecycle actions (aangevraagd -> ontvangen/verlopen)
- [x] 12.3 Add deadline tracking with reminder/escalation notification actions

## 13. Advice Panel and Form (V1)

- [x] 13.1 Create AdvicePanel.vue component showing advice requests with status badges, deadlines, overdue alerts, quick actions
- [x] 13.2 Create AdviceRequestDialog.vue form with adviseur selector, type toggle, onderwerp, deadline picker, questions
- [x] 13.3 Implement advice guard integration: block workflow transitions when pending advice exists
- [x] 13.4 Integrate AdvicePanel into CaseDetail.vue for all VTH case types

## 14. Quality and Testing (V1)

- [x] 14.1 Run `composer check:strict` and fix any PHPCS/PHPMD/Psalm/PHPStan errors
- [x] 14.2 Verify all new schemas import correctly via repair step (idempotent)
- [x] 14.3 Verify VTH workflow templates import via workflow engine and render in visual editor
