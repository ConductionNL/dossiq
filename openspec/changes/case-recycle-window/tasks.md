# Tasks: case-recycle-window

Tier: V1. Kind: code. Size M. Round 4 discovery cluster 39, candidates
C-case-core-11 (matrix hole), C-access-and-privacy-65,
C-access-and-privacy-50, C-documents-20 and C-access-and-privacy-14.
Decision D10, answered as both. openregister's half is
`delete-window-and-recorded-destruction`, merged 2026-09-14 as
openregister#3724 (`34e6b9ee`). Builds on dossiq `case-delete-guard`,
merged.

- [x] 1.1 `lib/Controller/CaseLifecycleController.php`: run
  `case-delete-guard` first, then hand a permitted delete to
  openregister's recycle state; ship no soft delete here (D-1). The guard
  runs first by construction rather than by call order: it listens on
  openregister's pre-persist `ObjectDeletingEvent`, so a held case never
  reaches the window whichever door the delete came through.
  - `tests/Unit/Controller/CaseLifecycleControllerTest.php`
  - `@spec openspec/changes/case-recycle-window/specs/case-management/spec.md`
- [x] 1.2 A deleted lens showing the date each case's window ends (D-2).
  It is its own page, `CasesDeleted`, and NOT a seventh quick-filter chip
  on `#Cases`: those chips filter the objects endpoint, which excludes
  soft-deleted rows by design, so a `deleted` chip would answer the empty
  set and read as a working lens over an empty trash.
  - `tests/vitest/deletedLens.spec.js`
- [x] 2.1 Restore as a recorded act, with who and when (D-3).
  - `tests/Unit/Service/CaseRecycleServiceTest.php`
- [x] 2.2 Destroy as a second act, recorded, permitted only to the role
  the case type declares, cascading to the process data (D-3, D-5). The
  cascade is openregister's `DestructionScopeService`; dossiq calls it and
  refuses when it cannot. An instance declaring no scope destroys exactly
  what it destroyed before, which is a valid answer and not an oversight.
  - `tests/Unit/Service/CaseDestructionTest.php`
- [x] 3.1 `caseType`: the destroying role (`destructionRole`) and the
  recovery window length (`recoveryWindowDays`). openregister sets the
  window from the SCHEMA, which is one schema for every case type, so the
  case-type number is what dossiq publishes and refuses a premature
  destruction against. It writes no second deletion marker.
- [x] 3.2 Carry the lawful-purpose end date beside the archive retention
  date, labelled, neither derived from the other (D-4). `case` gains
  `lawfulPurposeEndDate`; `caseType` gains `lawfulPurposeRetention` in
  months, counted from the case's end date.
  - `tests/Unit/Service/RetentionClocksTest.php`
- [x] 4.1 Dutch and English strings.
- [x] 4.2 The openregister slug is recorded above.
  C-documents-20's document half stays with filinq: deleting a document is
  allowed only to the record manager role, and filinq owns the document.
  dossiq declares the role on the case type and nothing more.
- [x] 4.3 `tests/e2e/case-recycle-window.spec.ts`: delete, find in the
  lens, restore, refuse a destruction inside the window, refuse one with
  no declared role, and read the two clocks apart;
  `openspec validate case-recycle-window --strict`.

## What was left out, and why

- **No destruction runs in the e2e suite.** A destruction cannot be
  undone, so a suite that swept slightly too widely would take real work
  with it the first time a filter was wrong. The act itself is pinned in
  `CaseDestructionTest`, which asserts the permanent delete reached
  openregister; the e2e asserts the refusals and the lens.
- **"A handler without the role is refused" has no e2e.** Playwright runs
  as admin and an admin holds every right. The e2e asserts the other half
  of the same rule, a case type declaring no role, and the unit test
  covers the handler who holds no role.
- **C-access-and-privacy-14, "a person deletes everything they own in one
  act", is not built.** It is a `could`, and it is cluster 38's data
  subject request rather than this window.
