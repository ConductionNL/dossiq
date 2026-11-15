# Tasks: bulk-actions-report-progress

Tier: V1. Kind: code. Size M. Round 4 discovery cluster 52, candidates
C-case-core-1 (matrix hole), C-case-core-2, C-case-core-4, C-case-core-45,
C-reporting-30, C-case-core-3, C-search-17 and C-configuration-20. No
decision. The openregister half landed as `bulk-action-jobs`
(openregister#3742, `f88d986b5`), and its contract is what this consumes.

- [x] 1.1 Hand the shipped bulk status transition and bulk reassignment to
  openregister's job; delete the browser iteration (D-1).
  - `lib/BulkAction/TransitionCasesAction.php`, `LifecycleCasesAction.php`,
    `ReassignCasesAction.php`, `SetCaseAttributeAction.php`
  - `lib/Service/Bulk/BulkJobHandoff.php`, `lib/Controller/BulkJobHandoffController.php`
  - Deleted: `lib/Service/BulkStatusTransitionService.php`,
    `lib/Service/SelectionReassignmentService.php`, and the four routes
    that looped.
  - `tests/Unit/Service/BulkHandoffTest.php`, `tests/Unit/BulkAction/CaseBulkActionsTest.php`
  - `@spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md`
- [x] 1.2 Render progress, the per-row outcome and the skip list as a list
  a handler can open (D-2).
  - `src/components/bulk/BulkJobProgress.vue`, `src/services/bulkJobApi.js`
  - `tests/vitest/bulkProgress.spec.js`
- [x] 1.3 A test that fails when dossiq regains a bulk loop over cases
  (D-1).
  - `tests/Unit/Architecture/NoBulkLoopOverCasesTest.php`. Mutation-checked:
    a loop put back reddens it, removing it greens it again.
- [x] 2.1 Require and store a written justification on a bulk
  distribution, refusing an empty one (D-3).
  - Declared by `ReassignCasesAction::requiresJustification()`, enforced by
    the hand-off, and said before the click by the dialog's disabled button.
  - `tests/Unit/BulkAction/CaseBulkActionsTest.php`
- [x] 2.2 Refuse a bulk attribute change spanning two case-type versions,
  at selection time, naming both (D-4).
  - `lib/Service/Bulk/CaseTypeVersionGuard.php`, raised before the job is
    created so no rehearsal runs.
- [x] 3.1 The select-all affordance says the scope and the number in
  words, with whole-result selection as a second act (D-5).
  - `src/utils/selectionScope.js`, `src/components/bulk/BulkSelectionScope.vue`
  - `tests/vitest/selectAllScope.spec.js`
- [x] 3.2 `lib/Controller/SubstitutionController.php`: build the selection
  and hand it to the same job, with the same justification (D-6).
  - `SubstitutionController::releaseCaseload()` and
    `CaseReassignmentService::releaseCaseload()`, which replaced the loop in
    `execute()`.
- [x] 4.1 Dutch and English strings.
  - 45 strings each in `l10n/nl.json`, `l10n/nl.js`, `l10n/en.json`, `l10n/en.js`.
- [x] 4.2 Record the openregister slug here once that lane opens it, and
  ask it for the simulation and the downloadable error report that
  C-case-core-2 and C-reporting-30 name.
  - The slug is `bulk-action-jobs`. Both are there and both are consumed:
    `POST /api/bulk-jobs` answers a rehearsed job whose members carry the
    per-case outcome, and `GET /api/bulk-jobs/{id}/download` answers the
    CSV the progress panel links to.
- [x] 4.3 `tests/e2e/bulk-actions-report-progress.spec.ts`: run a bulk act
  over a seeded set with refusals, read the skip list, be refused without
  a justification, be refused across two versions, and read the select-all
  scope; `openspec validate bulk-actions-report-progress --strict`.
  - The select-all scope is asserted in `tests/vitest/selectAllScope.spec.js`
    rather than the e2e: it is a client-side affordance over a count, and
    driving it through a browser would assert the same computed sentence
    through more machinery.

## What was deliberately left out

- **The task half of a caseload release is not a job.** A flow task is
  OpenRegister's own record with its own `reassign` verb, not an object in
  the case register, so the bulk job cannot walk it.
  `CaseReassignmentService::releaseCaseload()` moves the tasks in the request
  that orders the release and hands only the CASES to the job. Leaving the
  tasks behind until somebody commits is the failure the gesture exists to
  prevent.
- **A QUERY selection is not version-guarded by dossiq.** The case-type
  version refusal resolves the selected cases, which an id selection makes
  cheap and a query selection does not. OpenRegister's own `homogeneity`
  guard still refuses such a selection at creation, before a single object is
  touched; what it cannot do is name the CASE TYPE versions, because it reads
  schema versions.
