# Design: process-step-configuration

## Architecture Overview

This change is additive on top of `workflow-definition-model`. It introduces a single new shape — `StepConfig` — that hangs off each `WorkflowStep` inside a published `workflowTemplate`. Validation is a pure helper; persistence reuses the existing `WorkflowDefinitionService`. Two consumers (`status-transition-engine` and the runtime task creator) read the new fields. One repair step backfills the existing seeded bezwaar workflow. No new top-level OpenRegister schema; no new controller routes.

```
WorkflowDefinitionDialog.vue (existing)
└── WorkflowStepsEditor.vue (existing)
    └── StepConfigPanel.vue (new, per-step collapsible)
        ├── SlaInput.vue (hours / business-days toggle)
        ├── RequiredFieldsPicker.vue (multi-select from caseType fields)
        ├── AutoActionsList.vue (existing automatic-actions catalog)
        └── EscalationRuleEditor.vue (target role + offset)

Backend
├── WorkflowDefinitionService::publish() (existing)
│   └── StepConfigValidator::validate(step.config) (new)  ← rejects malformed config
├── StatusTransitionService::getAvailableTransitions() (existing)
│   ├── reads step.config.requiredFields as implicit guard
│   └── triggers step.config.autoActions via existing pipeline
├── TaskCreatorService::createForStep() (existing)
│   ├── stamps task.dueAt from step.config.sla
│   └── stamps task.escalationAt + escalationTarget from step.config.escalationRule
└── Migration/BackfillBezwaarStepConfig.php (new repair step)
```

## Entity Model

`WorkflowStep.config` (new, optional sub-object on every embedded step in `workflowTemplate.steps`):

| Property | Type | Description |
|----------|------|-------------|
| `sla` | object | `{ value: integer, unit: enum(hours|businessDays|calendarDays) }` — soft deadline; absence = no SLA |
| `requiredFields` | array<string> | Case-field property paths that MUST be populated to complete the step |
| `autoActions` | array<ActionRef> | Action keys + parameters fired on step completion |
| `escalationRule` | object | `{ trigger: enum(slaBreached|preBreach), offset: integer, offsetUnit: enum(hours|businessDays), notifyRole: reference, escalateToRole: reference, openIncident: boolean }` |

All four properties are optional. An empty `config` (or absent `config`) preserves today's V1 behaviour (no SLA, no implicit field guards, no auto-actions, no escalation). The shape is additive and backwards-compatible: existing seeded templates without `config` continue to work.

### ActionRef

Already defined by the `automatic-actions` spec. Reused verbatim: `{ key: string (enum from action catalog), parameters: object }`. No new action keys are introduced by this change.

## Validation Contract (`StepConfigValidator`)

Pure function. No I/O. Inputs: the `WorkflowStep` and the parent `caseType` (for field-reference resolution). Outputs: an array of structured validation errors with `path` + `code` + `message` keys.

Rules:

1. `sla.value` MUST be a positive integer ≤ 10000.
2. `sla.unit` MUST be one of the three enum values.
3. Every `requiredFields[i]` MUST resolve to a property defined on the linked `caseType` schema.
4. Every `autoActions[i].key` MUST be present in the `automatic-actions` action catalog.
5. `escalationRule.notifyRole` and `escalateToRole` MUST resolve to roleTypes defined on the linked `caseType`.
6. `escalationRule` MUST be absent if `sla` is absent (escalation requires a deadline).
7. `escalationRule.offset` MUST be ≤ `sla.value` when `trigger == preBreach`.

Validation runs on `WorkflowDefinitionService::publish()` — *not* on draft save. Drafts may hold malformed config; publish refuses it.

## Engine Consumption (Read-Only)

`status-transition-engine` already evaluates explicit guards on transitions. This change adds an *implicit* guard derived from the *current step's* `config.requiredFields`: if any required field is empty for the case in flight, exit transitions from that step's status are blocked. The user-facing message format is identical to the existing `requiredField` guard: `"Vereist veld ontbreekt: '<label>'"`.

`config.autoActions` are appended to the existing `automaticActions` pipeline already invoked when a step is completed. Order: step-level auto-actions fire first, then transition-level auto-actions (when the completion also triggers a transition). Failures of individual auto-actions are logged but do NOT roll back the step completion (consistent with `status-transition-engine` REQ — see "Transition triggers automatic actions").

`TaskCreatorService` stamps two new fields onto the created task at activation time:

- `task.dueAt = activatedAt + sla.value [in sla.unit]`
- `task.escalationAt = task.dueAt - escalationRule.offset [in offsetUnit]` (when `trigger == preBreach`)
- `task.escalationAt = task.dueAt + escalationRule.offset` (when `trigger == slaBreached`)

A separate background runner (out of scope here, owned by `automatic-actions`) walks tasks whose `escalationAt < now` and fires the configured `notifyRole` / `escalateToRole` / `openIncident` side-effects.

## Storage Decision

`config` is stored inline inside each step entry of the existing `steps` JSON string on `workflowTemplate`. We deliberately do NOT promote `StepConfig` to a top-level OpenRegister schema:

1. The `workflow-definition-model` design decision keeps the whole template as one immutable object on publish; introducing a separate entity would defeat that.
2. There is no read pattern that wants step configs without the step.
3. Step configs are never referenced cross-template; co-location is correct.

Trade-off: `config` cannot be edited without rewriting the parent template. Acceptable — the `workflow-definition-model` clone-and-publish cycle covers config edits the same way it covers structural edits.

## Editor UI (Low-Fidelity)

```
Step: "Toets ontvankelijkheid"  status: Ontvankelijkheidstoets   role: Behandelaar
  [x] isRequired           [3 checklist items]      ▾ Geavanceerd

  ┌── Geavanceerd ──────────────────────────────────────────────────┐
  │  SLA:        [  10  ] [ werkdagen ▼ ]                            │
  │                                                                  │
  │  Verplichte velden:  [ × isTimely ] [ × belanghebbendheid ]      │
  │                      [ + Veld toevoegen ▼ ]                      │
  │                                                                  │
  │  Automatische acties bij afronden:                                │
  │    1. notifySteller    parameters: {…}    [edit] [×]            │
  │    2. logToAuditTrail  parameters: {…}    [edit] [×]            │
  │    [ + Actie toevoegen ▼ ]                                       │
  │                                                                  │
  │  Escalatie:                                                       │
  │    Trigger:        [ pre-breach ▼ ]                              │
  │    Marge:          [  2  ] [ werkdagen ▼ ] vóór deadline         │
  │    Waarschuw:      [ Behandelaar ▼ ]                             │
  │    Escaleer naar:  [ Afdelingshoofd ▼ ]                          │
  │    [ ] Maak ook een incident aan                                  │
  └──────────────────────────────────────────────────────────────────┘
```

Form-only in V1. Visual escalation designer is V2.

## Integration with Other Specs

- `workflow-definition-model` — owns the parent `workflowTemplate` and its lifecycle; this change adds an additive sub-shape and a publish-time validator.
- `status-transition-engine` — consumer of `config.requiredFields` (implicit guard) and `config.autoActions` (step-completion side-effects).
- `automatic-actions` — provides the action catalog that `StepConfigValidator` rule 4 checks against; no new action keys here.
- `bezwaar-lifecycle` / `bezwaar-decision` — beneficiaries of the seeded SLAs in the repair step.

## Migration Plan

`BackfillBezwaarStepConfig` runs once per upgrade and is idempotent (re-running is a no-op):

1. Load the seeded bezwaar `workflowTemplate` (`isActive: true`, lowest published version).
2. For each step whose `id` matches a known bezwaar-step key, attach the corresponding `config` block (e.g. "Toets termijn" → `{ sla: { value: 5, unit: businessDays }, requiredFields: ["isTimely"], escalationRule: { trigger: preBreach, offset: 1, offsetUnit: businessDays, notifyRole: "Behandelaar", escalateToRole: "Afdelingshoofd", openIncident: false } }`).
3. Re-publish the template by bumping its `version` and creating a new published record (NOT mutating the existing one — `workflow-definition-model` guarantees published versions are immutable).
4. Deprecate the previous version. Open bezwaar cases stay pinned to their existing `workflowVersion` and are unaffected.

Pre-existing SLAs encoded in `BezwaarLifecycleService` constants are removed in the same change after the repair step lands.
