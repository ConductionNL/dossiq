# Design: case-lifecycle-on-the-page

## Context

`CaseDetail` (`src/manifest.json`, route `/cases/:id`, type `detail`) already
carries a KPI row (`case-kpi-time-left`, `case-kpi-casetype`,
`case-kpi-progress`) and a `lifecycleActions` block that cannot render.
`StatusTransitionService` computes the allowed transitions per user
(`GET /api/case/{caseId}/available-transitions`, route
`statusTransition#available`) and executes one
(`POST /api/case/{caseId}/transition`, route `statusTransition#execute`).
`DeadlinePauseService::resumeAfterPauze()` and `DeadlineExtensionService`
exist as services; only the termijn instance carries a `hervat` route
(`termijn#hervat`). The E2E fixtures already ship `seedStateMachine`,
`getAvailableTransitions` and `executeTransition`
(`tests/e2e/helpers/fixtures.ts`).

`@conduction/nextcloud-vue` 2.40.0 ships `CnLifecycleActions` (server-driven,
posts to OpenRegister), `CnTimelineStages` (a stepper) and `CnStatusBadge`.

ADR-032 kind: **code**. The centre of mass is two Vue widgets and one
controller seam. The manifest edits ride along.

## Goals / Non-Goals

**Goals:**

- A handler advances, closes, suspends, resumes, extends or reopens a case
  from the case page.
- The case page says which step the case is in.
- Every write goes through `StatusTransitionService` (guards, side effects,
  audit), never through a raw status write.

**Non-Goals:**

- Projecting the per-type graph onto OpenRegister's lifecycle (that is
  `case-status-onto-engine-lifecycle`).
- Bulk transitions (`one-case-list` and `case-bulk-status-transition`).
- The checklist a status brings with it (`checklist-per-status`).

## Decisions

### D1: a dossiq-side widget now, config later

`CnLifecycleActions` with no `config.transitions` asks OpenRegister and renders
what comes back; for `case` that is nothing. Two ways out:

1. Wait for `case-status-onto-engine-lifecycle` (11 of 15 tasks done, the last
   four wait on OpenRegister expressing a graph over a reference field).
2. A small widget that reads dossiq's `available-transitions` and posts to
   `transition`.

Option 2 ships now and is deleted when option 1 lands. The widget is
`case-transitions`, type `custom`, page slot `widget-case-transitions` bound to
`src/components/case/CaseTransitionsWidget.vue`. It renders one `NcButton` per
transition, in the order the template lists them, and a confirm dialog with a
comment field. The buttons carry the transition's `label`; the target status
name sits in the dialog.

### D2: a final transition asks for the result

When the target `statusType.isFinal` is true, the dialog adds a required
`result` select over `resultType` rows of the case type (REQ-CM-15 records the
result; this decision only moves the question onto the transition). The
service receives `resultTypeId` in the transition payload and writes the
`result` object before the status moves.

### D3: suspend, resume, extend and reopen are Actions menu entries

They are `headerActions` of type `handler` on `CaseDetail`, grouped under the
existing Actions menu. Each opens a dialog with a reason field and posts to a
new route on `CaseLifecycleController`:

| action | route | service |
|---|---|---|
| Suspend | `POST /api/case/{caseId}/suspend` | `DeadlinePauseService` |
| Resume | `POST /api/case/{caseId}/resume` | `DeadlinePauseService::resumeAfterPauze` |
| Extend term | `POST /api/case/{caseId}/extend` | `DeadlineExtensionService` |
| Reopen | `POST /api/case/{caseId}/reopen` | `StatusTransitionService` with the reopen scope check from `ZrcController::checkReopenScope` |

Suspend is offered only when `caseType.suspensionAllowed` is true, Extend term
only when `caseType.extensionAllowed` is true, Reopen only when
`case.isFinalStatus` is true. Delete stays as REQ-CDV-12 defines it, including
the legal-hold guard.

### D4: the stepper reads statusType rows, not milestones

`case-steps` is a `custom` widget (slot `widget-case-steps`,
`src/components/case/CaseStepsWidget.vue`) rendering `CnTimelineStages` over
the `statusType` rows where `caseType = case.caseType`, sorted by `order`. The
row equal to `case.status` is the active stage; rows before it are done. It
takes the grid cell of `case-kpi-progress`, which is removed together with its
milestone endpoint call on this page (the endpoint stays for other readers).

### D5: `CnTimelineStages` is a component, not a widget type

The declarative vocabulary has no `stepper` type. `CnTimelineStages` renders
inside a `custom` widget. A `stepper` widget type in nextcloud-vue would make
`case-steps` a config declaration; that request is listed in tasks as blocked.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | reason |
|---|---|---|
| Which transitions a case allows | imperative, existing `StatusTransitionService` | The graph is per case type and lives in `workflowTemplate.transitions`; OR's `x-openregister-lifecycle` is enum-anchored and cannot express it yet (triage #1). Declared exception per ADR-031: lifecycle guard. |
| Suspend, resume, extend | imperative, existing services | Deadline arithmetic with statutory rules (Awb 4:5, 4:14). |
| The stepper | read-only view over `statusType` rows | No behaviour; a widget. |

## Seed Data

No schema changes. The demo data already carries per-type `statusType` rows
with `order` and `isFinal` and `workflowTemplate` transitions, so the widget
has something to show on every seeded case.

## Risks / Trade-offs

- Two entry points for a while: the widget and the Workflow board both call
  `StatusTransitionService`. Acceptable: one service, two callers.
- `CnLifecycleActions` still renders on `TaskDetail` through OpenRegister. Two
  visual idioms for one gesture until the engine route lands. Mitigated by
  using the same button component and confirm dialog shape.
- The four new routes are `#[NoAdminRequired]` and must guard per case
  (gate no-admin-idor). They reuse `CaseAccessGuard`.
