# Tasks: case-split-surface

Tier: V1. Kind: code. Size S. The surface half of rows 2.35 and 2.45.

- [x] 1.1 `lib/Service/Cases/CaseSplitPerformer.php`: read, open, perform,
  relate (D-2).
  - `@spec openspec/changes/case-split-surface/specs/case-management/spec.md`
  - `tests/Unit/Service/Cases/CaseSplitPerformerTest.php`
- [x] 1.2 `lib/Controller/CaseSplitController.php` and the two routes; the
  refusal sentence passes through unchanged (D-3).
- [x] 2.1 `src/dialogs/CaseSplitDialog.vue` and the `case-split` header
  action beside `case-merge`; the picker asks what may be divided (D-1).
  - `tests/vitest/caseSplitSurface.spec.js`
- [x] 3.1 Dutch and English strings for the dialog and its refusals.
- [x] 3.2 `tests/e2e/case-split-surface.spec.ts`; `openspec validate
  case-split-surface --strict`.
