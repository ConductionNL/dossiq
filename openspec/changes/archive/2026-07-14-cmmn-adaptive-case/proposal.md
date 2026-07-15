# Proposal: cmmn-adaptive-case

kind: code — new capability. Adds a second, adaptive process-handling model (CMMN — Case
Management Model and Notation) alongside procest's existing structured BPMN-style workflow engine
(`WorkflowTemplateLoader` / `StatusTransitionService` / `Service/Transitions/*`).

## Why

Procest's workflow engine (`workflowTemplate` + `StatusTransitionService::execute()`) is a
predetermined state machine: a `caseType` declares a fixed set of statuses and guarded
transitions between them, and every case of that type walks the same graph. This fits highly
regulated, repeatable processes well (vergunningen, bezwaar, subsidie) — which is why it is the
default and stays untouched by this change.

Many government cases do not fit that shape. Complex social-domain casework, enforcement
trajectories with unpredictable escalation paths, and multi-party dossiers require the case
worker to decide the next step based on how the case has actually evolved — not a step the
process designer could enumerate in advance. Forcing these into a BPMN-style transition graph
either explodes the graph into an unmaintainable combinatorial mess of guarded transitions, or
strips out the guards and becomes a free-form status field with no structure at all.

CMMN is the OMG standard purpose-built for this: a case plan model of stages, tasks, and
milestones, made adaptive through **discretionary items** (optional tasks the worker may choose
to activate) and **sentries** (event/condition-triggered entry and exit criteria), rather than a
single fixed transition graph. Procest already borrows CMMN vocabulary in two places —
`task.status` uses the CMMN HumanTask lifecycle enum (`available/active/completed/terminated/
disabled`, missing `enabled`) and `workflowTemplate` carries `x-cmmn-equivalent: CasePlanModel` as
a documentation annotation — but neither is a real CMMN engine: there is no plan-item state
machine, no sentries, no discretionary-item gating, no milestones-as-plan-items. This change adds
that engine as a genuine second option, not a relabelling of the first.

## What Changes

- **NEW schema `caseModel`** (register.d) — the CMMN case-plan **definition** (data, stored as an
  OR object): `caseFileItems`, nestable `stages`, `humanTasks`, `milestones`, and `sentries`
  (entry/exit criteria), each plan item flagged `discretionary` or mandatory. JSON representation
  only; CMMN-XML import is a documented follow-up (`design.md` §7).
- **MODIFIED `caseType`** — additive `handlingModel` enum (`bpmn` default | `cmmn`) declaring which
  engine drives cases of that type. A caseType is never driven by both at once (`design.md` §5).
- **MODIFIED `case`** — additive `casePlanState` field (JSON-encoded), the CMMN runtime's single
  write path: per-plan-item lifecycle state, achieved milestones, and a bounded case-file/event
  log. Present only when `caseType.handlingModel = cmmn`; the BPMN `status`/`statusHistory` fields
  are untouched and remain the source of truth for BPMN-handled cases.
- **NEW `lib/Service/Cmmn/CaseModelEngine.php`** — the pure, deterministic runtime: plan-item
  lifecycle state machine (`available → enabled → active → completed/terminated/disabled`,
  milestones `available → completed/terminated`), sentry evaluation (entry/exit, multi-criteria,
  OR-across-sentries / AND-within-a-sentry), discretionary-item enablement gating, milestone
  achievement. Illegal transitions throw, never silently no-op.
- **NEW `lib/Service/Cmmn/CaseModelLoader.php`** — loads the active `caseModel` for a `caseType`,
  mirroring `WorkflowTemplateLoader`'s per-request memoisation pattern.
- **NEW `lib/Controller/CmmnCaseController.php`** + routes — get case plan, enable a discretionary
  item, complete/terminate a human task, signal a case-file/event.
- **NEW UI** `src/views/cases/components/CmmnCasePlanPanel.vue` — case-detail widget (grouped by
  stage, state badges, enable/complete/terminate actions), registered as a `kind: "widget"`
  manifest slot on the existing `CaseDetail` page, following the `CaseAssistantPanel` pattern.
  Renders nothing when the case's `caseType.handlingModel !== 'cmmn'`.
- **NO changes** to `WorkflowTemplateLoader`, `StatusTransitionService`, `Service/Transitions/*`,
  or any `workflowTemplate`-driven caseType/case. BPMN and CMMN coexist as sibling engines
  selected per caseType (`design.md` §5); this change does not touch the DMN engine being built
  concurrently in a separate worktree.

## Impact

- Affected specs: NEW `cmmn-adaptive-case`.
- Affected code: `lib/Service/Cmmn/*` (new), `lib/Controller/CmmnCaseController.php` (new),
  `lib/Settings/register.d/70-cmmn-case-model.json` (new), `lib/Service/SettingsService.php`
  (additive `caseModel` slug→config-key mapping), `appinfo/routes.php` (new routes),
  `src/views/cases/components/CmmnCasePlanPanel.vue` (new), `src/services/cmmnApi.js` (new),
  `src/utils/cmmnHelpers.js` (new), `src/registry.js` + `src/manifest.json` (new widget slot),
  `l10n/en.json` + `l10n/nl.json` (new keys).
- No new composer/npm dependencies. No migration required — `handlingModel`/`casePlanState` are
  additive, optional fields; existing BPMN cases are unaffected.
