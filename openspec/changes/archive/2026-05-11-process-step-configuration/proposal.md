# Proposal: Process Step Configuration

## Summary

Extend the existing `process-step-configuration` spec (which today covers only CRUD of steps inside a workflow and step-to-task mapping) with a tenant-admin **per-step configuration surface**: SLA / timeouts, mandatory case-field declarations, on-complete automatic actions, and escalation rules. Today these knobs are hard-coded inside `StatusTransitionService`, `CaseFieldValidator`, and the bezwaar-specific repair step, which means every tenant gets the same SLAs and the same set of required fields per status, and any change requires a developer commit. This change exposes them as declarative `step.config` data hung off each `WorkflowStep` in the `workflowTemplate` schema and consumed read-only by `status-transition-engine` and the runtime task creator.

## Problem

`workflow-definition-model` (just merged via PR #347) introduced `WorkflowStep` as an embedded object but only on the structural axis: which step, which status, which role, ordered, optionally required, with a checklist. The behavioural axis is missing: how long a step may take before it escalates, which case fields must be populated to mark it complete, what automatic actions to fire on completion, and how to escalate when a step runs over its SLA. `status-transition-engine` (PR #352) has placeholders for these but no model to read from. Tenant admins cannot tune step-level behaviour without a developer; the bezwaar-specific 6-week SLA is currently in a constant in `BezwaarLifecycleService`.

## Affected Projects

- [ ] Project: `procest` — Extend `WorkflowStep` with a `config` sub-object in `lib/Settings/procest_register.json`, add `StepConfigValidator` (pure validation, no persistence), wire `StatusTransitionService` to honour `config.sla` / `config.requiredFields` / `config.autoActions` / `config.escalationRule`, add a Vue editor for the config block inside the existing `WorkflowDefinitionDialog`, and migrate the hard-coded bezwaar SLAs into seeded `config` blocks.

## Scope

### In Scope (V1)

- **Per-step `config` sub-object** (REQ-PSC-3): `sla`, `requiredFields[]`, `autoActions[]`, `escalationRule` on every `WorkflowStep`, declarative and optional.
- **`StepConfigValidator`** (REQ-PSC-4): structural validation at definition-publish time; rejects unknown action keys, out-of-range SLA, dangling field references.
- **Engine consumption** (REQ-PSC-5..6): `status-transition-engine` reads `requiredFields` and `autoActions`; the runtime task creator stamps a `dueAt` from `sla` and an `escalationAt` from `escalationRule`.
- **Admin editor** (REQ-PSC-7): Vue panel embedded in the existing step row of `WorkflowDefinitionDialog` — no separate route.
- **Seed migration** (REQ-PSC-8): repair step lifts the hard-coded bezwaar SLAs and required-field lists into `config` on the seeded bezwaar `workflowTemplate`.

### Out of Scope

- Visual escalation-tree designer — V2.
- Custom-expression action keys (free-form JSONPath / JS) — Enterprise; V1 ships a closed enum.
- Tenant-level overrides on a published definition without cloning — explicitly rejected; honours `workflow-definition-model` immutability.
- Status-level (rather than step-level) configuration — owned by `status-transition-engine`.

## Approach

1. Extend the `WorkflowStep` shape in the existing `workflowTemplate` schema with an optional `config` object (additive, backwards compatible).
2. Add `lib/Service/StepConfigValidator.php` — a pure-function validator invoked by `WorkflowDefinitionService::publish()` before transition to `published`.
3. Teach `StatusTransitionService::getAvailableTransitions()` and the task creator to read `config.requiredFields` (as an additional implicit guard) and `config.autoActions` (executed in the existing automatic-action pipeline). Stamp `task.dueAt` from `config.sla`.
4. Surface the config block in `WorkflowDefinitionDialog.vue` as a collapsible "Geavanceerd" panel per step row; no new dialog.
5. Repair step `BackfillBezwaarStepConfig` migrates the hard-coded `BezwaarLifecycleService` constants into `config` on the seeded `workflowTemplate`.

## Cross-Project Dependencies

- **`workflow-definition-model` (procest)** — extends the `WorkflowStep` embedded shape; publish-time validation hooks into `WorkflowDefinitionService::publish()`.
- **`status-transition-engine` (procest)** — consumer; reads `config.requiredFields` and triggers `config.autoActions`.
- **OpenRegister** — additive schema change to `workflowTemplate`; no new top-level schema.
- **`automatic-actions` (procest)** — `config.autoActions` items reference action keys already defined by that spec; no new action surface introduced here.
