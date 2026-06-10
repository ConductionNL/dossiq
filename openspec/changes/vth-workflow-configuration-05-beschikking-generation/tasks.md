# Tasks: vth-workflow-configuration-05-beschikking-generation


> **Build status (hydra audit 2026-06-10).** `lib/Service/BeschikkingGenerationService.php::generateBeschikking()` + the wider `BeschikkingService` (compose, akkoord, onderteken, verzend, verifyMandaat, exportAuditPacket, archive) + `BeschikkingController` are on dev. Template-management Vue UI remains greenfield.
Beschikking generation service + template UI. Traces to giant Tasks 6, 7.

## 1. BeschikkingGenerationService

- [ ] Implement `generateBeschikking(caseId, decisionType)`
- [ ] Load beschikkingTemplate for (caseType, decisionType)
- [ ] Validate required fields; return field-named error if missing
- [ ] Implement merge-field substitution ({{applicantName}} → case data)
- [ ] Generate HTML/PDF (HTML-to-PDF fallback if Docudesk unavailable)
- [ ] Save generated document via FileService and attach to case bijlagen
- [ ] Implement `BeschikkingController.generate()` endpoint with per-object guard

## 2. Template Management UI

- [ ] Create `BeschikkingTemplatesTab.vue` listing templates by zaaktype/decisionType
- [ ] Build `BeschikkingTemplateEditor.vue` (name, decision-type, content editor, merge-field list, validity dates)
- [ ] Implement merge-field insertion (autocomplete/drag-drop/button)
- [ ] Add "Test generation" button with sample-data selector
- [ ] On save, call the beschikking service to version the template

## 3. Tests

- [ ] Test all merge fields with sample data
- [ ] Test required-field validation
- [ ] Test template versioning (new generations use current version only)
- [ ] Test Docudesk integration path when available
