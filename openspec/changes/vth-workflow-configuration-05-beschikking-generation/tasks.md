# Tasks: vth-workflow-configuration-05-beschikking-generation


> **Build status (hydra audit 2026-06-10).** `lib/Service/BeschikkingGenerationService.php::generateBeschikking()` + the wider `BeschikkingService` (compose, akkoord, onderteken, verzend, verifyMandaat, exportAuditPacket, archive) + `BeschikkingController` are on dev. Template-management Vue UI remains greenfield.
Beschikking generation service + template UI. Traces to giant Tasks 6, 7.

## 1. BeschikkingGenerationService

- [x] Implement `generateBeschikking(caseId, decisionType)` (verified on dev: `lib/Service/BeschikkingGenerationService.php::generateBeschikking($zaakId, $outcome, $motivation)`)
- [x] Load beschikkingTemplate for (caseType, decisionType) (verified on dev: BeschikkingService template resolution path)
- [x] Validate required fields; return field-named error if missing (verified on dev: BeschikkingGenerationService merge-field validation)
- [x] Implement merge-field substitution (`{{applicantName}}` → case data) (verified on dev: BeschikkingGenerationService::generateBeschikking)
- [x] Generate HTML/PDF (HTML-to-PDF fallback if Docudesk unavailable) (verified on dev: MockTemplateEngineAdapter + Docudesk adapter)
- [x] Save generated document via FileService and attach to case bijlagen (verified on dev: BeschikkingService::compose/onderteken/verzend)
- [x] Implement `BeschikkingController.generate()` endpoint with per-object guard (verified on dev: `lib/Controller/BeschikkingController.php`)

## 2. Template Management UI

- [~] Create `BeschikkingTemplatesTab.vue` listing templates by zaaktype/decisionType (greenfield Vue work; deferred to dedicated VTH admin UI sprint)
- [~] Build `BeschikkingTemplateEditor.vue` (name, decision-type, content editor, merge-field list, validity dates) (greenfield)
- [~] Implement merge-field insertion (autocomplete/drag-drop/button) (greenfield)
- [~] Add "Test generation" button with sample-data selector (greenfield)
- [~] On save, call the beschikking service to version the template (greenfield)

## 3. Tests

- [~] Test all merge fields with sample data (deferred to vth-workflow-configuration-10-testing)
- [~] Test required-field validation (deferred to vth-workflow-configuration-10-testing)
- [~] Test template versioning (new generations use current version only) (deferred to vth-workflow-configuration-10-testing)
- [~] Test Docudesk integration path when available (deferred to vth-workflow-configuration-10-testing)
