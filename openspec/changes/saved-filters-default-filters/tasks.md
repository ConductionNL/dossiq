# Tasks: saved-filters-default-filters

> Checkbox budget: 1 task x 2 = 2 unindented `- [ ]` lines (cap 20).

## Implementation tasks

### Task 1: Declare the defaults on Cases and Queue
- **spec_ref**: `openspec/changes/saved-filters-default-filters/specs/case-lists/spec.md#requirement-the-case-lists-ship-default-filters`
- **files**: `src/manifest.json`, `l10n/nl.json`, `l10n/nl.js`, `tests/schemas/app-manifest-v2.schema.json`, `tests/vitest/defaultSavedFilters.spec.js`
- **acceptance_criteria**:
  - Cases and Queue carry the defaults; each condition equals its chip's
  - Dutch names in the catalogue
- [x] Implement
- [x] Test
- [x] Woo requests are a default filter on Cases (`woo-requests`, the Woo chip's filter), not a page of their own (Ruben, 19 October 2026)
