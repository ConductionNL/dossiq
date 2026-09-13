# Tasks: archived-cases-leave-the-lenses

Tier: V1. Kind: config. Row Q2.33. Waits on openregister
`object-archive-state` (open on openregister `development`).

- [ ] 1.1 `src/manifest.json` `#CaseDetail`: Archive and Restore, with
  `visibleIf` on a final status and on the archive marker (D-2, D-5).
  - `tests/vitest/caseActionsMenu.spec.js`
  - `@spec openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md`
- [ ] 1.2 `lib/Settings/dossiq_register.json`: archiving writes
  `case.archiveStatus` beside the platform marker, and reading asks the
  marker (D-1). The ZGW delete guard is untouched.
- [ ] 2.1 `#Cases` and `#Queue` lenses exclude archived cases; an Archived
  lens shows them; `#MyWorkHome` tiles and the case search take the same
  default (D-3).
  - `tests/vitest/caseListLenses.spec.js`
- [ ] 2.2 An archived case renders without edit affordances and shows the
  platform's refusal when a write is attempted (D-4).
- [ ] 3.1 `tests/e2e/archived-cases-leave-the-lenses.spec.ts`;
  `openspec validate archived-cases-leave-the-lenses --strict`.
