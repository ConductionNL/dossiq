# Tasks: transition-reports-failed-actions

Tier: V1. Kind: code. Carried from draft PR #2712.

- [x] 1.1 `StatusTransitionService::execute()` and `executeFreeForm()`:
  `failedActions()` and `outcome()`, `status` is `ok` or `partial`.
  - `@spec openspec/changes/transition-reports-failed-actions/specs/status-transition-engine/spec.md`
- [x] 1.2 `BulkStatusTransitionService::execute()` carries `failedActions` per
  succeeded case.
  - `@spec openspec/changes/transition-reports-failed-actions/specs/case-bulk-status-transition/spec.md`
- [x] 1.3 `CaseActionProvider::execute()` documents `status` as `ok` or
  `partial` and names `failedActions`.
- [x] 2.1 `src/utils/transitionOutcome.js`: one plural-aware warning, used by
  the case-page dialog and the workflow board.
- [x] 2.2 `BulkTransitionDialog`: count the cases that moved without all of
  their actions.
- [x] 3.1 PHPUnit: `StatusTransitionServiceFailedActionsTest` (ok and partial,
  both paths), `BulkStatusTransitionServiceTest` (carried and defaulted). Each
  new test watched failing with the change reverted.
- [x] 3.2 Vitest: `caseTransitionOutcome.spec.js`, the bulk helper and dialog
  specs.
- [x] 4.1 l10n: the two plural strings in `en` and `nl`, catalogues regenerated.
