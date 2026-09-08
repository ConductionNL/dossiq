---
kind: code
depends_on: []
---

# Proposal: task-on-the-case

Round 2 competitor analysis, row A06 of
`concurrentie-analyse/procest/_round2/compare/placement.md`. Rung 4 on the
placement ladder: a small dossiq component behind an existing manifest
widget, because nextcloud-vue 2.40.0 cannot render the pane from
configuration alone. One noun: the next step.

## Why

You cannot finish a task on the case page. Widget `case-tasks` on
`CaseDetail` (`src/manifest.json`, an `object-list` over `caseTask` with
`case = @objectId`) shows five columns and routes every row to `TaskDetail`.
There the lifecycle buttons render, labelled with the schema's transition
descriptions ("Pick up the task.", "Mark task as completed."), but nothing
confirms the write, nothing leads back to the case, and the next task is
two navigations away (`_round2/dossiq-baseline/journeys.md` J5,
`pages/TaskDetail.md`). Every competitor keeps the task beside the case data:
gzac's Taken pane with Taak afronden and a toast
(`valtimo/round2/pages/CaseDetail-TaskPanel.md`), zaaksysteem's Taken panel
with one tick per task (`xxllnc-zaken/round2/pages/Case-Fasen.md`), opencase's
Action column with a toast on approval (findings A06).

The buttons themselves are not the problem. `dossiq-defect-triage.md` item 1
establishes that `CnLifecycleActions` is server driven and that
`caseTask.status` carries a valid `x-openregister-lifecycle` block
(`lib/Settings/dossiq_register.json`: activate, complete, terminate,
disable), so `available-actions` answers for a task where it stays empty
for a case. The task pane can reuse the component exactly as `TaskDetail`
does today.

## What changes

- **A06, an inline task pane.** Widget `case-tasks` on `CaseDetail` shows the
  first open task of the case with its lifecycle buttons in place. Finishing
  it shows a toast and the next open task takes its place; when none is
  left the pane says so. The remaining open tasks stay listed under the
  pane, and View all keeps leading to the Tasks list filtered on the case.
- **A way back.** `TaskDetail` names the case the task belongs to and links
  to it. The `task-waiting-case` widget (`case-flow-human-steps` 6.1) only
  renders for a task holding a flow run; this link renders for every task
  with a case.
- **Target and interim.** Target: `case-tasks` stays an `object-list` with a
  lifecycle column, once nextcloud-vue's `CnObjectListWidget` accepts row
  actions. Interim, `[blocked: nextcloud-vue 2.40.0 CnObjectListWidget has no
  rowActions and no lifecycle column]`: a dossiq `CaseTaskPane` component
  behind the same widget id, type `custom`, resolved through the page slot
  `widget-case-tasks` in `src/registry.js` the way `widget-task-waiting-case`
  is. It adds no page, so the ADR-100 page ratchet is untouched.

## Adjacent change

`case-flow-human-steps` owns the run side: a completed task resumes the
flow node that asked for it (`TaskCompletionResumeListener`) and the next
task is created by the flow. This change owns the page side: the completion
is performed from the case, is confirmed, and the task the flow created
next becomes visible without leaving the page. The two meet at the `complete`
transition and share no file.

## Capabilities

- `task-management`: ADDED REQ-TASK-014 (a task is finished on the case
  page), REQ-TASK-015 (a task links back to its case).

## Out of scope

- Case status transitions on the case page (`case-lifecycle-on-the-page`,
  triage item 1): the case's status graph is per case type and
  `available-actions` cannot serve it.
- A checklist per status (split off at placement, section 3).
- Creating a task from the case with the case prefilled (findings D05,
  triage item 6).
- Task reassignment from the pane (`reassignment-is-a-bulk-action`).

## Impact

Kind code: one Vue component, one registry slot, one manifest widget
retyped, one manifest note on `TaskDetail`, one e2e spec. No PHP, no schema
change: the `caseTask` lifecycle block and OpenRegister's `available-actions`
route already do the work.
