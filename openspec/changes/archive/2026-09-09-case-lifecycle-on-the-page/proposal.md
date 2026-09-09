---
kind: code
depends_on: []
---

# Proposal: case-lifecycle-on-the-page

Round 2 competitor analysis, rows A02 and A23 of
`concurrentie-analyse/procest/_round2/compare/placement.md`. Rung 2 on the
placement ladder: widgets on an existing page. One noun: the case's state.

## Why

A handler cannot move a case from the case page. `CaseDetail` declares
`lifecycleActions: {field: "status"}` and renders nothing, because
`case.status` is a `statusType` reference and OpenRegister's `available-actions`
answers `{"actions": []}` for it (`dossiq-defect-triage.md` #1). The Workflow
board is the only surface that advances a case. The progress tile on the case
reads milestones and shows 0% on every demo case, so nobody can tell which step
the case is in.

Every competitor puts the state control and the phase on the case page:

- OpenCase: `opencase/round2/case-detail-anatomy.md` (Actions: Close case,
  Reopen, Archive).
- GZAC: `valtimo/round2/case-detail-anatomy.md` (status pill, kebab with
  Claimen, Start) and `valtimo/round2/pages/CaseDetail-Voortgang.md` (the
  current task highlighted on the process).
- Zaaksysteem: `xxllnc-zaken/round2/pages/Case-Zaakacties.md` and
  `xxllnc-zaken/round2/case-detail-anatomy.md` (FASE AFRONDEN, Opschorten,
  Hervatten, Termijn wijzigen; a phase strip with done and active phases).
- Dossiq baseline: `_round2/dossiq-baseline/case-detail-anatomy.md` (KPI row,
  no transition control) and `_round2/dossiq-baseline/journeys.md` J3 and J5.

## What Changes

- A `case-transitions` widget in the header row of `CaseDetail`. It lists the
  transitions the case type's `workflowTemplate` allows from the current
  status, filtered by `StatusTransitionService` for the signed-in user, and
  posts the chosen one to dossiq's own transition endpoint. A transition into a
  final status asks for the result first.
- The Actions menu on `CaseDetail` gains Suspend, Resume, Extend term, Reopen
  and Delete. Delete keeps the legal-hold guard from REQ-CDV-12.
- A stepper widget over the case type's `statusType` rows in `order`, the
  current status marked, replacing `case-kpi-progress`.
- The dead `lifecycleActions` block on `CaseDetail` goes.
- **BREAKING** for nothing: the Workflow board keeps its drag path through the
  same service.

## Interim and durable route

The durable fix is the open change `case-status-onto-engine-lifecycle`
together with `workflow-definitions-to-flow`, which project the per-type graph
onto OpenRegister so `available-actions` starts answering for cases. Until
then the widget posts to dossiq. When OpenRegister's lifecycle can express a
graph over a reference field, or `CnLifecycleActions` accepts a transition
endpoint per page (`placement.md` section 3), the widget becomes a config
declaration and this code is deleted.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `status-transition-engine`: available transitions render on the case page
  and execute from it; a final transition records a result; suspend, resume,
  extend and reopen are reachable from the case.
- `case-dashboard-view`: the case shows its step among the case type's
  statuses; the progress tile goes.

## Impact

- `src/manifest.json`: page `CaseDetail` (widgets `case-transitions`,
  `case-steps`, layout, `headerActions`, removal of `lifecycleActions` and
  `case-kpi-progress`).
- `src/components/case/CaseTransitionsWidget.vue` and
  `src/components/case/CaseStepsWidget.vue` (new, registered as page slots).
- `appinfo/routes.php` and a controller method for suspend, resume and extend
  on a case, over the existing `DeadlinePauseService` and
  `DeadlineExtensionService`.
- E2E: `tests/e2e/case-lifecycle-on-the-page.spec.ts` (new) and
  `tests/e2e/case-detail-kpis-and-tabs.spec.ts` (the KPI row loses a tile).
- No schema change. No effect on Pipelinq's request-to-case bridge.
