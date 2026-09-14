# Tasks: bulk-actions-report-progress

Tier: V1. Kind: code. Size M. Round 4 discovery cluster 52, candidates
C-case-core-1 (matrix hole), C-case-core-2, C-case-core-4, C-case-core-45,
C-reporting-30, C-case-core-3, C-search-17 and C-configuration-20. No
decision. Waits on openregister's bulk job, to be specified in
openregister, wave 1, proposed there as `bulk-action-jobs`.

- [ ] 1.1 Hand the shipped bulk status transition and bulk reassignment to
  openregister's job; delete the browser iteration (D-1).
  - `tests/unit/Service/BulkHandoffTest.php`
  - `@spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md`
- [ ] 1.2 Render progress, the per-row outcome and the skip list as a list
  a handler can open (D-2).
  - `tests/vitest/bulkProgress.spec.js`
- [ ] 1.3 A test that fails when dossiq regains a bulk loop over cases
  (D-1).
- [ ] 2.1 Require and store a written justification on a bulk
  distribution, refusing an empty one (D-3).
  - `tests/unit/Service/BulkJustificationTest.php`
- [ ] 2.2 Refuse a bulk attribute change spanning two case-type versions,
  at selection time, naming both (D-4).
- [ ] 3.1 The select-all affordance says the scope and the number in
  words, with whole-result selection as a second act (D-5).
  - `tests/vitest/selectAllScope.spec.js`
- [ ] 3.2 `lib/Controller/SubstitutionController.php`: build the selection
  and hand it to the same job, with the same justification (D-6).
- [ ] 4.1 Dutch and English strings.
- [ ] 4.2 Record the openregister slug here once that lane opens it, and
  ask it for the simulation and the downloadable error report that
  C-case-core-2 and C-reporting-30 name.
- [ ] 4.3 `tests/e2e/bulk-actions-report-progress.spec.ts`: run a bulk act
  over a seeded set with refusals, read the skip list, be refused without
  a justification, be refused across two versions, and read the select-all
  scope; `openspec validate bulk-actions-report-progress --strict`.
