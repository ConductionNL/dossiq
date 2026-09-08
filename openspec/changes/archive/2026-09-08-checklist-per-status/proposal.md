---
kind: config
depends_on: []
---

# Proposal: checklist-per-status

Round 2 competitor analysis, row A20 of
`concurrentie-analyse/procest/_round2/compare/placement.md`. Rung 1 on the
placement ladder: a schema property, read by a handler that already exists.
One noun: what each status brings with it.

## Why

A case type says which statuses a case walks through, but not what has to be
done in each of them. `caseTask.checklist` is a list inside one task, and
`CreateTaskHandler` creates a task only when a transition names it in its
`automaticActions`. So a handler who moves a case into In behandeling gets an
empty task pane and works from memory, and two handlers on the same case type
do different things in the same phase.

Both competitors ship the work with the phase:

- Zaaksysteem: `xxllnc-zaken/round2/case-detail-anatomy.md` (Taken (4) per
  phase, the FASE INCOMPLEET label) and
  `xxllnc-zaken/round2/pages/CaseTypeV1-milestones.md` (the checklist dialog
  on a phase).
- GZAC: `valtimo/round2/case-definition-editor-anatomy.md` (user tasks per
  process step).
- Dossiq baseline: `_round2/dossiq-baseline/case-detail-anatomy.md` (the
  Tasks widget, empty on every demo case).

## What Changes

- `statusType.checklist`: a list of items, each a `title` and a `required`
  flag, authored on the case type.
- When a case enters a status, the engine turns every item into a
  `createTask` action and the existing `CreateTaskHandler` creates the tasks
  on the case. Entering the same status twice does not create them twice.
- A required item holds the case in its status until its task is completed.
  The transition buttons on the case page say which item is still open.
- The Tasks pane on the case page shows the tasks. Nothing changes there.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `status-transition-engine`: a status brings its checklist tasks; a required
  item is a guard on leaving the status.

## Impact

- `lib/Settings/dossiq_register.json`: property `checklist` on `statusType`.
- `lib/Service/Transitions/StatusChecklist.php` (new): reads the target
  status and yields the `createTask` actions and the required titles.
- `lib/Service/StatusTransitionService.php`: both transition paths dispatch
  the checklist actions after the status write.
- `lib/Service/Transitions/StatusChecklistGuard.php` (new) and
  `GuardRegistry`: the implicit guard for required items.
- `lib/Settings/register.d/`: checklist items on the seeded bezwaar type.
- `l10n/en.json`, `l10n/nl.json`: the guard message.
- E2E: `tests/e2e/checklist-per-status.spec.ts` (new).
- Adjacent: `task-on-the-case` (A06) styles the pane these tasks land in;
  `case-type-one-authoring-surface` gives the admin the form to edit the
  list. Until that lands the list is edited on the case type's JSON.
