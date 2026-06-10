# Tasks: vth-workflow-configuration-05-beschikking-generation

Beschikking generation service + template UI. Traces to giant Tasks 6, 7.

## 1. BeschikkingGenerationService

- [~] Implement `generateBeschikking(caseId, decisionType)` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Load beschikkingTemplate for (caseType, decisionType) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Validate required fields; return field-named error if missing — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement merge-field substitution ({{applicantName}} → case data) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Generate HTML/PDF (HTML-to-PDF fallback if Docudesk unavailable) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Save generated document via FileService and attach to case bijlagen — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `BeschikkingController.generate()` endpoint with per-object guard — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. Template Management UI

- [~] Create `BeschikkingTemplatesTab.vue` listing templates by zaaktype/decisionType — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Build `BeschikkingTemplateEditor.vue` (name, decision-type, content editor, merge-field list, validity dates) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement merge-field insertion (autocomplete/drag-drop/button) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Add "Test generation" button with sample-data selector — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] On save, call the beschikking service to version the template — deferred to downstream cycle / fleet-wide adoption (handoff)

## 3. Tests

- [~] Test all merge fields with sample data — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test required-field validation — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test template versioning (new generations use current version only) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test Docudesk integration path when available — deferred to downstream cycle / fleet-wide adoption (handoff)
