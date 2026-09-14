# Tasks: case-recycle-window

Tier: V1. Kind: code. Size M. Round 4 discovery cluster 39, candidates
C-case-core-11 (matrix hole), C-access-and-privacy-65,
C-access-and-privacy-50, C-documents-20 and C-access-and-privacy-14.
Decision D10, answered as both. Waits on openregister's recycle state, to
be specified in openregister, wave 1, beside `object-archive-state` and
the shipped `retention-management` spec. Waits on dossiq
`case-delete-guard`, open.

- [ ] 1.1 `lib/Controller/CaseLifecycleController.php`: run
  `case-delete-guard` first, then hand a permitted delete to
  openregister's recycle state; ship no soft delete here (D-1).
  - `tests/unit/Controller/CaseLifecycleControllerTest.php`
  - `@spec openspec/changes/case-recycle-window/specs/case-management/spec.md`
- [ ] 1.2 `src/manifest.json`: a deleted lens on `#Cases`, showing the
  date each case's window ends (D-2).
  - `tests/vitest/deletedLens.spec.js`
- [ ] 2.1 Restore as a recorded act, with who and when (D-3).
- [ ] 2.2 Destroy as a second act, recorded, permitted only to the role
  the case type declares, cascading to the process data (D-3, D-5).
  - `tests/unit/Service/CaseDestructionTest.php`
- [ ] 3.1 `caseType`: the destroying role and the recovery window length.
- [ ] 3.2 Carry the lawful-purpose end date beside the archive retention
  date, labelled, neither derived from the other (D-4).
  - `tests/unit/Service/RetentionClocksTest.php`
- [ ] 4.1 Dutch and English strings.
- [ ] 4.2 Record the openregister slug here once that lane opens it, and
  hand filinq C-documents-20's document half with the candidate id.
- [ ] 4.3 `tests/e2e/case-recycle-window.spec.ts`: delete, find in the
  lens, restore, destroy as the wrong role and as the right one, and read
  the two clocks apart;
  `openspec validate case-recycle-window --strict`.
