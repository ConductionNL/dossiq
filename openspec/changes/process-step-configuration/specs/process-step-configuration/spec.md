---
status: draft
---
# process-step-configuration Specification

## Purpose

Extend the existing `process-step-configuration` capability (step CRUD inside a workflow + step-to-task mapping) with a declarative per-step configuration surface — SLA, mandatory case-field declarations, on-complete automatic actions, and escalation rules — that tenant admins can edit per step and that `status-transition-engine` and the runtime task creator consume read-only.

## Context

`workflow-definition-model` (PR #347) introduced `WorkflowStep` as an embedded structural unit: ordered, status-bound, role-bound, optionally required, with a checklist. The behavioural axis — how long the step may take, which fields must be filled to complete it, what side-effects fire on completion, how to escalate when it runs over — is hard-coded today in `StatusTransitionService`, `CaseFieldValidator`, and the bezwaar-specific `BezwaarLifecycleService`. Tenants cannot tune these knobs without a developer release. This change exposes them as an additive `config` sub-object on every `WorkflowStep` and adds the validation, engine consumption, admin editor, and bezwaar backfill needed to put them into production. The shape is additive and backwards-compatible: existing seeded templates without `config` retain V1 behaviour exactly.

## Requirements

---

### REQ-PSC-1: Step Config Sub-Object on WorkflowStep

The system SHALL support an optional `config` sub-object on every embedded `WorkflowStep` inside `workflowTemplate.steps`. The shape is `{ sla?, requiredFields?, autoActions?, escalationRule? }`. All sub-fields SHALL be optional; absent `config` SHALL preserve the V1 behaviour of `workflow-definition-model` exactly.

**Feature tier**: V1

#### REQ-PSC-1-001: Schema accepts step config

- **GIVEN** the Procest app has run the latest repair step extending the `workflowTemplate` schema
- **WHEN** an administrator saves a `workflowTemplate` draft whose step "Toets ontvankelijkheid" carries `config: { sla: { value: 5, unit: "businessDays" }, requiredFields: ["isTimely"] }`
- **THEN** the draft SHALL persist without validation errors
- **AND** reading the draft back SHALL return the `config` sub-object byte-for-byte
- **AND** other steps in the same draft that omit `config` SHALL remain unchanged

#### REQ-PSC-1-002: Absent config preserves V1 behaviour

- **GIVEN** a published `workflowTemplate` whose every step omits the `config` property
- **WHEN** a case in that workflow enters the status "In behandeling"
- **THEN** the runtime SHALL behave identically to V1 of `workflow-definition-model`: no SLA stamping, no implicit field guards, no step-level auto-actions, no escalation tasks

---

### REQ-PSC-2: SLA on a Step

The system SHALL allow an SLA to be expressed per step as `{ value: positive integer, unit: enum("hours" | "businessDays" | "calendarDays") }`. When an SLA is present, the task creator SHALL stamp `task.dueAt` at step activation time and the status-transition engine SHALL surface the deadline on the case detail.

**Feature tier**: V1

#### REQ-PSC-2-001: dueAt stamped from step SLA at activation

- **GIVEN** step "Inhoudelijke beoordeling" has `config.sla = { value: 10, unit: "businessDays" }`
- **WHEN** case "ZK-2026-0042" enters the status that owns this step at 2026-05-11 09:00 Amsterdam local time
- **THEN** the system SHALL create a task whose `dueAt` is 10 working days after activation (skipping weekends and Dutch public holidays per the existing platform calendar)
- **AND** the task detail UI SHALL render the deadline as "Verloopt op …"

#### REQ-PSC-2-002: SLA value bounds enforced at publish

- **GIVEN** an administrator drafts a `workflowTemplate` containing a step with `config.sla.value = 0`
- **WHEN** the administrator attempts to publish the draft
- **THEN** the publish SHALL be refused with a generic user-facing error
- **AND** the structured validation error SHALL be logged with `path: steps[i].config.sla.value` and `code: out_of_range`

---

### REQ-PSC-3: Required-Fields Guard from Step Config

The system SHALL treat each entry of `config.requiredFields[]` on the case's current step as an implicit `requiredField` guard on every exit transition from that step's status. The user-facing block message SHALL match the existing transition-level `requiredField` guard format.

**Feature tier**: V1

#### REQ-PSC-3-001: Missing required field blocks exit transition

- **GIVEN** the case is in the status whose current step has `config.requiredFields = ["resultaat"]`
- **AND** the case has no value for the field "resultaat"
- **WHEN** the handler views the case detail
- **THEN** every exit transition button from that status SHALL be disabled
- **AND** the tooltip SHALL display `"Vereist veld ontbreekt: 'Resultaat'"` (label resolved via the linked caseType's field metadata)

#### REQ-PSC-3-002: Filled required field unblocks exit transitions

- **GIVEN** the same step and the case now has "resultaat" populated
- **WHEN** the handler views the case detail
- **THEN** the exit transitions whose own guards are otherwise satisfied SHALL be enabled
- **AND** no `"Vereist veld ontbreekt"` tooltip SHALL appear

---

### REQ-PSC-4: StepConfigValidator at Publish

The system SHALL validate every step's `config` at the moment of publishing a `workflowTemplate` draft. Validation SHALL be a pure-function check (no I/O) and SHALL reject malformed config without persisting it. Draft saves SHALL NOT trigger validation.

**Feature tier**: V1

#### REQ-PSC-4-001: Unknown auto-action key refused

- **GIVEN** a draft `workflowTemplate` whose step has `config.autoActions = [{ key: "doesNotExist", parameters: {} }]`
- **WHEN** the administrator clicks "Publiceren"
- **THEN** `StepConfigValidator::validate()` SHALL return an error with `code: unknown_action_key`
- **AND** `WorkflowDefinitionService::publish()` SHALL refuse the lifecycle flip
- **AND** the response SHALL contain a generic static error string (no raw validator output)
- **AND** the structured error SHALL be logged via `logger->warning()`

#### REQ-PSC-4-002: Dangling required-field reference refused

- **GIVEN** a draft step references `config.requiredFields = ["thisFieldDoesNotExist"]` and the linked caseType has no such property
- **WHEN** the administrator clicks "Publiceren"
- **THEN** the publish SHALL be refused with logged error `code: unknown_field_reference`

#### REQ-PSC-4-003: Escalation without SLA refused

- **GIVEN** a draft step with `config.escalationRule` present but `config.sla` absent
- **WHEN** the administrator clicks "Publiceren"
- **THEN** the publish SHALL be refused with logged error `code: escalation_requires_sla`

#### REQ-PSC-4-004: Draft saves bypass validation

- **GIVEN** the same malformed draft from REQ-PSC-4-001
- **WHEN** the administrator clicks "Opslaan" (draft save) instead of "Publiceren"
- **THEN** the draft SHALL persist successfully with its malformed `config`
- **AND** the validator SHALL NOT be invoked

---

### REQ-PSC-5: Step Auto-Actions on Completion

The system SHALL execute `config.autoActions[]` on the current step when the step is completed. Step-level auto-actions SHALL fire BEFORE transition-level auto-actions when the completion event also drives a transition. Failures of individual auto-actions SHALL be logged but SHALL NOT roll back the step completion.

**Feature tier**: V1

#### REQ-PSC-5-001: Step auto-actions fire on completion

- **GIVEN** step "Advies opstellen" has `config.autoActions = [{ key: "notifySteller", parameters: {} }, { key: "logToAuditTrail", parameters: { event: "advice_complete" } }]`
- **WHEN** the handler marks the step complete
- **THEN** the platform SHALL invoke "notifySteller" first, then "logToAuditTrail"
- **AND** both actions SHALL fire BEFORE any transition-level `automaticActions` if the completion also triggers a transition

#### REQ-PSC-5-002: Auto-action failure does not roll back step completion

- **GIVEN** the same step where "notifySteller" raises an exception at runtime
- **WHEN** the handler marks the step complete
- **THEN** the step SHALL remain completed in the case state
- **AND** the failure SHALL be logged via `logger->error()` with the action key and an internal exception id
- **AND** the next auto-action ("logToAuditTrail") SHALL still execute

---

### REQ-PSC-6: Escalation Rule on a Step

The system SHALL support a per-step `escalationRule` declaring when and how to escalate when the step approaches or breaches its SLA. The rule SHALL include `trigger` (`preBreach` or `slaBreached`), `offset` + `offsetUnit`, `notifyRole`, `escalateToRole`, and `openIncident`. The task creator SHALL stamp `task.escalationAt` at activation time; a downstream runner (owned by `automatic-actions`) drives the actual side-effects.

**Feature tier**: V1

#### REQ-PSC-6-001: escalationAt stamped on activation for preBreach trigger

- **GIVEN** step "Bezwaar afhandelen" has `config.sla = { value: 42, unit: "calendarDays" }` and `config.escalationRule = { trigger: "preBreach", offset: 5, offsetUnit: "calendarDays", notifyRole: "Behandelaar", escalateToRole: "Afdelingshoofd", openIncident: false }`
- **WHEN** the case enters the status owning this step on 2026-05-11
- **THEN** the created task SHALL have `dueAt = 2026-06-22` (42 calendar days later)
- **AND** the task SHALL have `escalationAt = 2026-06-17` (5 calendar days before `dueAt`)
- **AND** the task SHALL persist the `notifyRole`, `escalateToRole`, and `openIncident` values for the downstream runner

#### REQ-PSC-6-002: escalationAt for slaBreached trigger lands after dueAt

- **GIVEN** the same step but with `escalationRule.trigger = "slaBreached"` and `offset = 2, offsetUnit = "businessDays"`
- **WHEN** the case enters the status on 2026-05-11 (a Monday)
- **THEN** the task SHALL have `escalationAt = dueAt + 2 business days`

---

### REQ-PSC-7: Admin Editor Panel per Step

The system SHALL surface step configuration as a collapsible "Geavanceerd" panel rendered inside the existing per-step row of `WorkflowStepsEditor`. The panel SHALL be writable only on `draft` definitions and SHALL render read-only on `published` or `deprecated` definitions. No separate route or dialog SHALL be introduced.

**Feature tier**: V1

#### REQ-PSC-7-001: Editor visible only on draft

- **GIVEN** the administrator opens a `workflowTemplate` in lifecycle status `draft`
- **WHEN** the administrator expands a step row and clicks "Geavanceerd"
- **THEN** the SLA / required-fields / auto-actions / escalation inputs SHALL all be editable
- **AND** saving the draft SHALL persist the new `config` block without invoking the validator

#### REQ-PSC-7-002: Editor read-only on published

- **GIVEN** the administrator opens the same template after publishing it
- **WHEN** the administrator expands a step row and clicks "Geavanceerd"
- **THEN** every input SHALL render in a disabled state
- **AND** the panel SHALL display a banner: "Gepubliceerde versies zijn niet bewerkbaar — kloon eerst een nieuwe versie."

#### REQ-PSC-7-003: Editor strings localised

- **GIVEN** the administrator's Nextcloud locale is `nl_NL`
- **WHEN** the panel renders
- **THEN** every label, placeholder, and error message SHALL be loaded via `this.t(appName, '...')` against the Dutch translation catalogue (consistent with `i18n` ADR)

---

### REQ-PSC-8: Backfill Bezwaar Step Config

The system SHALL provide a repair step that migrates the hard-coded SLAs and required-field lists from `BezwaarLifecycleService` into `config` on the seeded bezwaar `workflowTemplate`. The migration SHALL respect `workflow-definition-model` immutability: it SHALL clone the existing published version, attach the `config` blocks, publish the new version, and deprecate the previous version. Open cases pinned to the previous version SHALL be unaffected.

**Feature tier**: V1

#### REQ-PSC-8-001: Seeded bezwaar workflow gets config blocks

- **GIVEN** the Procest app has the seeded bezwaar `workflowTemplate` at `version: 1, lifecycleStatus: published` with hard-coded SLAs in `BezwaarLifecycleService`
- **WHEN** the repair step runs
- **THEN** a new `version: 2` of the same template SHALL be created with `config` blocks attached to every known bezwaar step ("Toets termijn", "Toets belanghebbendheid", "Toets besluit-karakter", "Plan hoorzitting", "Stel advies op", "Neem beslissing", "Verstuur besluit")
- **AND** the new version SHALL be `lifecycleStatus: published, isActive: true`
- **AND** version 1 SHALL be moved to `lifecycleStatus: deprecated, isActive: false`
- **AND** `caseType.workflowDefinition` for the Bezwaar case type SHALL point at version 2

#### REQ-PSC-8-002: Open cases stay pinned to the prior version

- **GIVEN** three open bezwaar cases were created against version 1 (with `case.workflowVersion = 1`)
- **WHEN** the repair step publishes version 2
- **THEN** the three open cases SHALL still resolve their definition to version 1 via `WorkflowDefinitionService::getDefinitionForCase()`
- **AND** their behaviour SHALL be unchanged (no new SLAs retroactively applied)

#### REQ-PSC-8-003: Repair step is idempotent

- **GIVEN** the repair step has already run once (version 2 with `config` is active)
- **WHEN** the repair step runs a second time
- **THEN** it SHALL detect that the active version already has populated `config` on at least one step
- **AND** it SHALL exit without creating a version 3 and without modifying any record

## Dependencies

- `workflow-definition-model` (procest) — owns the parent `workflowTemplate` schema and lifecycle; this spec extends its `WorkflowStep` shape additively and hooks into `WorkflowDefinitionService::publish()` for validation
- `status-transition-engine` (procest) — consumes `config.requiredFields` (implicit guard) and `config.autoActions` (step-completion pipeline)
- `automatic-actions` (procest) — provides the action catalog `StepConfigValidator` checks against; provides the escalation runner that fires on `task.escalationAt`
- OpenRegister — additive schema change to `workflowTemplate`; no new top-level schema

## Standards & References

- **ADR-000 (Data Model)**: `workflowTemplate`, `WorkflowStep`, `caseType` definitions — properties MUST match exactly; this change only ADDS `WorkflowStep.config`
- **ADR-001 (Data Layer)**: All persistence via `ObjectService` (3-positional-arg API); no custom Entity/Mapper for `StepConfig` (it is embedded)
- **ADR-004 (Frontend)**: Vue 2 Options API; imports only from `@conduction/nextcloud-vue`; `createObjectStore` for the parent `workflow-definition` entity (already registered by `workflow-definition-model`); never `window.confirm`
- **ADR-015 (Common Patterns)**: SPDX headers on every new file; error handling via `logger` with static user-facing messages — never `getMessage()` in responses; translation keys via `this.t(appName, '...')`
- **CMMN 1.1**: `config.sla` aligns with the PlanItem deadline concept; `config.autoActions` aligns with the on-complete event listener concept
- **AWB / Archiefwet**: Bezwaar SLAs preserved by REQ-PSC-8 are legally mandated; the immutable repair step (clone-and-publish, never mutate) keeps the audit trail clean
