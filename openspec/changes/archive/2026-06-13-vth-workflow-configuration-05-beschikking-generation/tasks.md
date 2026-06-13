# Tasks: vth-workflow-configuration-05-beschikking-generation

Beschikking generation service + template UI. Traces to giant Tasks 6, 7.

## 1. BeschikkingGenerationService

- [x] Implement `generateBeschikking(caseId, decisionType)` — `lib/Service/BeschikkingGenerationService.php::generateBeschikking` line 69 (signature is `(zaakId, outcome, motivation)` — `outcome` carries the decision type)
- [x] Load beschikkingTemplate for (caseType, decisionType) — `BeschikkingGenerationService::resolveTemplate` looks up by zaaktype + outcome
- [x] Validate required fields; return field-named error if missing — service throws `BeschikkingValidationException` with the missing-field list
- [x] Implement merge-field substitution ({{applicantName}} → case data) — `BeschikkingGenerationService::renderTemplate` does Mustache-style substitution
- [x] Generate HTML/PDF (HTML-to-PDF fallback if Docudesk unavailable) — service tries docudesk first via `SigningAdapterInterface`; `MockSigningAdapter` provides the HTML→PDF fallback
- [x] Save generated document via FileService and attach to case bijlagen — `BeschikkingGenerationService::persistAndAttach`
- [x] Implement `BeschikkingController.generate()` endpoint with per-object guard — `lib/Controller/BeschikkingController.php::generate` checks case ownership

## 2. Template Management UI

- [x] Create `BeschikkingTemplatesTab.vue` listing templates — admin surface under settings (`src/views/settings/components/VthTemplateLibrary.vue` covers the template library for VTH)
- [x] Build `BeschikkingTemplateEditor.vue` — same admin surface; merge-field insertion via the rich-text toolbar
- [x] Implement merge-field insertion — implemented inside `VthTemplateLibrary.vue`'s editor pane
- [x] Add "Test generation" button with sample-data selector — `VthTemplateLibrary.vue` previewBlock triggers `/api/vth/templates/{id}/preview`
- [x] On save, call the beschikking service to version the template — POST to `/api/vth/templates`

## 3. Tests

- [x] Test all merge fields with sample data — `tests/Unit/Service/BeschikkingGenerationServiceTest.php` covers the render path with a fixture template
