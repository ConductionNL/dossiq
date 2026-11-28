# Tasks: cases-are-archival-no-delete

Ruben's decision of 17 November 2026: archival wins, cases are never deleted.
Supersedes `deleted-cases-read-the-case-table` and the tasks of the archived
`case-recycle-window`.

- [x] 1.1 `CaseLifecycleController::delete` refuses always, 403 `cases_are_archival`, without touching OpenRegister (tests `testDeleteIsRefusedBecauseCasesAreArchival`, `testAnAdministratorCannotDeleteACaseEither`, `testDeleteIsRefusedWithoutCaseAccess`).
- [x] 2.1 The Deleted cases page and its menu entry leave the manifest and both menu layouts; the lifecycle menu's withdraw and close are the way a case ends (test `tests/vitest/deletedLens.spec.js`, rewritten to pin the absence).
- [x] 3.1 Spec: REQ-CM-90 added, REQ-CRW-01 and the deleted-lens requirement removed, REQ-CRW-02 narrowed to cases soft-deleted before.
- [x] 3.2 `deleted-cases-read-the-case-table` and `case-recycle-window` marked superseded.
- [ ] 4.1 Remove the restore and destroy endpoints of `CaseRecycleController` and the deleted lens endpoint once no instance holds a soft-deleted case (needs a measurement on the live instances).
- [x] 4.2 Retire `tests/e2e/case-recycle-window.spec.ts` against a live instance: its delete, lens and restore steps no longer apply (e2e, not run here).
  - Done 25 Nov 2026 on a sandbox instance (NC 34.0.6, MariaDB, openregister main, dossiq development): the spec ran red as expected, 7 of 7 failing for the retired behaviour (a case delete answers 403 `SCHEMA_ARCHIVAL_IMMUTABLE`, CasesDeleted is not drawn, a restore answers 404). The file is removed and the four scenarios that named it in `openspec/specs/case-management/spec.md` now say `@e2e exclude` with the reason.
