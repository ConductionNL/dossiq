# Tasks: saved-views-default-views

> Checkbox budget: 1 task x 2 = 2 unindented `- [ ]` lines (cap 20).

## Implementation tasks

### Task 1: Declare the default views on Cases and Queue
- **spec_ref**: `openspec/changes/saved-views-default-views/specs/case-lists/spec.md#requirement-the-case-lists-ship-default-views`
- **files**: `src/manifest.json`, `l10n/en.json`, `l10n/nl.json`, `l10n/en.js`, `l10n/nl.js`, `tests/schemas/app-manifest-v2.schema.json`, `tests/vitest/defaultSavedViews.spec.js`
- **acceptance_criteria**:
  - Cases and Queue carry the default views; each condition equals its chip's
  - Dutch names in the catalogue
- [x] Implement
- [x] Test
- [x] Woo requests are a default view on Cases (`woo-requests`, the Woo chip's filter), not a page of their own (Ruben, 19 October 2026)
