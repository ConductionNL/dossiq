# Tasks: case-claim-action

Tier: V1. Kind: config. Row 2.4.

- [ ] 1.1 `src/customComponents.js`: handlers `claimCase` and `releaseCase`
  over the object store update; no optimistic state.
  - vitest: each handler issues one update with the expected payload and
    surfaces a refused write unchanged
  - `@spec openspec/changes/case-claim-action/specs/case-management/spec.md`
- [ ] 1.2 `src/manifest.json` `#CaseDetail` header actions `case-claim`
  (`visibleIf` assignee empty) and `case-release` (`visibleIf` assignee is
  `@me`).
  - `tests/vitest/caseActionsMenu.spec.js`: both present with their visibility
- [ ] 1.3 `#Queue` row action Claim; `#Cases` row action Claim on rows
  without an assignee.
- [ ] 2.1 `tests/e2e/case-claim.spec.ts`; `openspec validate
  case-claim-action --strict`.
