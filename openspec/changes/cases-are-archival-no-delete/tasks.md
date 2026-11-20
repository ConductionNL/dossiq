# Tasks: cases-are-archival-no-delete

Ruben's decision of 17 November 2026: archival wins, cases are never deleted.
Supersedes `deleted-cases-read-the-case-table` and the tasks of the archived
`case-recycle-window`.

- [x] 1.1 `CaseLifecycleController::delete` refuses always, 403 `cases_are_archival`, without touching OpenRegister (tests `testDeleteIsRefusedBecauseCasesAreArchival`, `testAnAdministratorCannotDeleteACaseEither`, `testDeleteIsRefusedWithoutCaseAccess`).
- [x] 2.1 The Deleted cases page and its menu entry leave the manifest and both menu layouts; the lifecycle menu's withdraw and close are the way a case ends (test `tests/vitest/deletedLens.spec.js`, rewritten to pin the absence).
- [x] 3.1 Spec: REQ-CM-90 added, REQ-CRW-01 and the deleted-lens requirement removed, REQ-CRW-02 narrowed to cases soft-deleted before.
- [x] 3.2 `deleted-cases-read-the-case-table` and `case-recycle-window` marked superseded.
- [ ] 4.1 Remove the restore and destroy endpoints of `CaseRecycleController` and the deleted lens endpoint once no instance holds a soft-deleted case (needs a measurement on the live instances).
- [ ] 4.2 Retire `tests/e2e/case-recycle-window.spec.ts` against a live instance: its delete, lens and restore steps no longer apply (e2e, not run here).
