# Tasks: woo-case-type

## 1. Template Library

### Task 1: Create TemplateLibraryService
- **spec_ref**: `openspec/specs/woo-case-type/spec.md#requirement-1-woo-zaaktype-template-activation`
- **files**: `lib/Service/TemplateLibraryService.php`, `lib/Controller/TemplateLibraryController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a `woo-verzoek.json` template WHEN admin POSTs `/api/templates/woo-verzoek/activate` THEN a zaaktype with 8 statuses is created in the active register
  - GIVEN re-activation of the same template WHEN POSTed THEN existing zaaktype is updated, not duplicated
  - GIVEN field overrides in the payload WHEN activated THEN overrides applied while base template stays untouched
- [x] Implement listTemplates(), previewTemplate(), activateTemplate()
- [x] Implement TemplateLibraryController
- [x] Register routes

### Task 2: Ship WOO zaaktype template JSON
- **spec_ref**: `openspec/specs/woo-case-type/spec.md#requirement-2-woo-lifecycle-stages`
- **files**: `lib/Settings/templates/woo-verzoek.json`
- **acceptance_criteria**:
  - Template contains 8 ordered status types matching the WOO lifecycle
  - Template includes WOO intake property definitions, document types, role types, decision types, and version metadata
- [x] Author template JSON with full 8-stage lifecycle
- [x] Validate template via `jq` and OpenRegister importer dry run

## 2. Intake and Lifecycle

### Task 3: Build WOOIntakeForm
- **spec_ref**: `openspec/specs/woo-case-type/spec.md#requirement-3-woo-specific-intake-form`
- **files**: `src/views/cases/components/WOOIntakeForm.vue`
- **acceptance_criteria**:
  - Form renders verzoeker_naam, contactgegevens, type, onderwerp, periode_van/tot, bestuurlijke_aangelegenheid, kanaal
  - Required-field validation blocks save until satisfied
- [x] Build Vue form bound to property definitions on the WOO zaaktype

### Task 4: Implement WOODeadlineService
- **spec_ref**: `openspec/specs/woo-case-type/spec.md#requirement-4-woo-deadline-tracking-and-extension`
- **files**: `lib/Service/WOODeadlineService.php`
- **acceptance_criteria**:
  - GIVEN a WOO case created on 2026-05-01 WHEN deadline read THEN expectedResolution = 2026-05-29 (28 days)
  - GIVEN one extension applied with reason WHEN attempting a second extension THEN error returned
  - GIVEN deadline-7 reached WHEN nightly job runs THEN warning notification sent to behandelaar
- [x] Implement calculate(), extendDeadline(), checkAndWarn()
- [x] Wire into existing DeadlinePanel.vue

## 3. Document Assessment and Decision

### Task 5: Implement WOODocumentAssessmentService
- **spec_ref**: `openspec/specs/woo-case-type/spec.md#requirement-6-per-document-disclosure-assessment`
- **files**: `lib/Service/WOODocumentAssessmentService.php`, `lib/Controller/WOOAssessmentController.php`
- **acceptance_criteria**:
  - GIVEN 12 collected documents WHEN bulk-assess called with 11 classifications THEN advancing to "Lakken" is blocked with "Document 12 needs assessment"
  - GIVEN classification "deels openbaar" without weigeringsgrond WHEN saved THEN validation error returned
- [x] Implement bulkUpsert(), validate(), getOutstanding()
- [x] Implement controller endpoint

### Task 6: Build DocumentAssessmentTable
- **spec_ref**: `openspec/specs/woo-case-type/spec.md#requirement-6-per-document-disclosure-assessment`
- **files**: `src/views/cases/components/DocumentAssessmentTable.vue`
- **acceptance_criteria**:
  - Table renders one row per collected document with classification select and weigeringsgrond multi-select
  - Saving the table calls the bulkUpsert endpoint
- [x] Build Vue table component

### Task 7: Implement WOODecisionService and besluit flow
- **spec_ref**: `openspec/specs/woo-case-type/spec.md#requirement-8-woo-decision-besluit`
- **files**: `lib/Service/WOODecisionService.php`
- **acceptance_criteria**:
  - GIVEN every document assessed WHEN decision created THEN a `decision` object is linked to the case referencing all assessments
  - GIVEN unassessed document WHEN decision attempted THEN blocked with explicit error
- [x] Implement assembleDecision()
- [x] Wire into controller and besluit panel

## 4. Optional Docudesk Integration

### Task 8: Wire Docudesk redaction hook on "Lakken" stage
- **spec_ref**: `openspec/specs/woo-case-type/spec.md#requirement-7-redaction-via-docudesk-integration`
- **files**: `lib/Service/WOORedactionService.php`
- **acceptance_criteria**:
  - GIVEN Docudesk is installed AND case enters "Lakken / Anonimiseren" WHEN documents are flagged "deels openbaar" THEN they are queued in Docudesk for redaction
  - GIVEN Docudesk is not installed WHEN stage entered THEN UI falls back to manual upload-redacted-version flow
- [x] Implement WOORedactionService with feature detection
- [x] Add UI fallback for non-Docudesk installs
