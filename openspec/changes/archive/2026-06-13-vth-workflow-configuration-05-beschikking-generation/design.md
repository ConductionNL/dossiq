# Design: vth-workflow-configuration-05-beschikking-generation

## Architecture

`kind: code` member (ADR-032). Controller → Service → ObjectService/FileService (ADR-003/ADR-001). Beschikking templates are declarative data seeded by member 01 (ADR-031); this member is the generation engine and the editor over them.

## Service Layout

- `BeschikkingGenerationService.generateBeschikking(caseId, decisionType)`: load the (caseType, decisionType) template, validate required fields, substitute merge fields (`{{applicantName}}` → case data), render HTML/PDF (HTML-to-PDF fallback when Docudesk unavailable), persist via FileService, attach to the case bijlagen relation.
- `BeschikkingController.generate()` endpoint.

## UI

- `BeschikkingTemplatesTab.vue` lists templates by zaaktype/decisionType.
- `BeschikkingTemplateEditor.vue`: name/description, decision-type selector, content editor with merge-field picker, available merge fields from schema, validity dates, test-generation button. NcSelect carries inputLabel; editor dialogs are isolated files (ADR-004).

## Security (ADR-005)

Generation validates the caseId belongs to the caller (per-object guard). Template management is admin-only. Merge-field substitution escapes case data to avoid template injection.
