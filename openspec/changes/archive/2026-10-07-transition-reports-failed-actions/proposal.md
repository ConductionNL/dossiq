---
kind: code
depends_on: []
---

# Proposal: transition-reports-failed-actions

## Why

A status transition moves the case first and runs its automatic actions
after. `SideEffectDispatcher` records every action that failed as a row with
`ok: false`, but `StatusTransitionService::execute()` and `executeFreeForm()`
answered `status: "ok"` whatever those rows said.

On 2026-09-10 every `createTask` a phase asked for was refused for want of an
acting identity. Twelve refusals were written into the status records, and the
API still answered 200 with `ok`. A handler moved a case, was told it had
worked, and got none of the checklist the phase brings. Nothing on screen
separated that from a phase that asks for no work at all.

The existing requirement "Transition Execution" already says a failed action
SHALL NOT roll back the status and SHALL be logged. It says nothing about what
the caller is told. This change fills that gap.

## What changes

- `StatusTransitionService::execute()` and `executeFreeForm()` answer
  `status: "partial"` with a `failedActions` list (`type`, `error`, in
  dispatch order) when the case moved and at least one action failed. With no
  failure they answer `status: "ok"` and an empty `failedActions`. The HTTP
  status stays 200: the move the caller asked for did happen.
- A failure is logged at warning level with the case and the failed rows.
- `BulkStatusTransitionService::execute()` keeps the per-case status
  `succeeded` and carries the case's `failedActions` beside it. A new per-case
  status value would change what every existing reader means by `succeeded`;
  an extra key only adds to it.
- The case-page transition dialog and the workflow board warn when the answer
  names failed actions. The bulk dialog counts the cases that moved without
  all of their actions.
- `CaseActionProvider::execute()` documents `status` as `ok` or `partial`.

## Contract change

`status` on the single-case transition answer was documented as always `ok`.
It is now `ok` or `partial`. A reader that compares `status === 'ok'` to decide
whether the move happened will read a partial move as a failure. No reader in
dossiq or OpenRegister does that: OpenRegister reads only `to` off the provider
report, and dossiq's frontend reads the HTTP status. `failedActions` is a new
key and is always present.

## Capabilities

- Added requirement in `status-transition-engine`: a transition that moved
  without all of its actions says so.
- Added requirement in `case-bulk-status-transition`: a bulk move carries each
  case's failed actions.

## Out of scope

The case-page stages widget posts through OpenRegister's `/transition`, which
answers with the re-read object and discards the provider report. A partial
move made there is not shown to the handler until OpenRegister forwards the
report. That is OpenRegister's half.

## Impact

`lib/Service/StatusTransitionService.php`,
`lib/Service/BulkStatusTransitionService.php`,
`lib/Lifecycle/CaseActionProvider.php`, `src/utils/transitionOutcome.js`,
`src/dialogs/CaseTransitionConfirmDialog.vue`,
`src/dialogs/BulkTransitionDialog.vue`,
`src/views/workflow-board/WorkflowBoard.vue`, `l10n/en.*`, `l10n/nl.*`.
