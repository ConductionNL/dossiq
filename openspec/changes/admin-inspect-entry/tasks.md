# Tasks: admin-inspect-entry

Tier: V1. Kind: config. Row 2.22.

- [ ] 1.1 `src/manifest.json` `#CaseDetail`: action group `case-inspect`
  (`adminOnly`) with Raw data and Flow runs (D-1).
  - `tests/vitest/caseActionsMenu.spec.js`: present, admin only, two entries
  - `@spec openspec/changes/admin-inspect-entry/specs/case-management/spec.md`
- [ ] 2.1 `tests/e2e/admin-inspect.spec.ts`; `openspec validate
  admin-inspect-entry --strict`.
