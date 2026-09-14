# Tasks: case-followers

Tier: V1. Kind: config. Row 13.18. Waits on openregister
`object-watchers` (not yet a change on openregister `development`).

- [ ] 1.1 `src/manifest.json` `#CaseDetail`: Follow and Unfollow with
  `visibleIf` on the subscription (D-1).
  - `tests/vitest/caseActionsMenu.spec.js`
  - `@spec openspec/changes/case-followers/specs/case-management/spec.md`
- [ ] 1.2 `#Cases` lens Followed; `#MyWorkHome` tile Cases I follow.
  - `tests/vitest/caseListLenses.spec.js`
- [ ] 1.3 People tab: Followers section (D-2).
- [ ] 2.1 `tests/e2e/case-followers.spec.ts`; `openspec validate
  case-followers --strict`.
