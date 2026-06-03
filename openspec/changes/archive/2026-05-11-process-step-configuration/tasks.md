# Tasks: process-step-configuration

## Deduplication Check

- [ ] **D01**: Confirm the existing `process-step-configuration` spec at `openspec/specs/process-step-configuration/spec.md` covers ONLY step CRUD and step-to-task mapping — no SLA, no `config` sub-object, no escalation. Confirm `workflow-definition-model` is merged (PR #347) and `status-transition-engine` exists as a sibling change/spec (#352). Confirm `BezwaarLifecycleService.php` contains hard-coded SLA constants that must be migrated.

---

## Schema Extension

- [ ] **T01**: Extend the `workflowTemplate` schema in `lib/Settings/procest_register.json`. Each entry in the `steps` JSON-string array MAY carry an additional `config` sub-object with shape `{ sla: { value: int, unit: enum }, requiredFields: string[], autoActions: ActionRef[], escalationRule: { trigger, offset, offsetUnit, notifyRole, escalateToRole, openIncident } }`. All sub-fields optional; absent `config` preserves V1 behaviour. Bump the schema `version` field. Document the additive nature in the schema description.

---

## Backend: Validator

- [ ] **T02**: Create `lib/Service/StepConfigValidator.php`. Pure static class; no `use OCP\IUserSession`, no DI, no I/O. Public method: `validate(array $step, array $caseTypeSchema): array`. Returns `[]` on success, otherwise an array of error objects with keys `path`, `code`, `message`. Implements rules 1–7 from `design.md`. SPDX header on line 1. Never returns exception messages.

---

## Backend: Publish Hook

- [ ] **T03**: Wire `StepConfigValidator::validate()` into `WorkflowDefinitionService::publish()`. Before the lifecycle flip from `draft` → `published`, iterate `template.steps[]` and validate each step's `config`. If any error array is non-empty, refuse to publish and return a static error message (no raw error contents in the response — log the structured errors via `$this->logger->warning()`).

---

## Backend: Engine Consumption

- [ ] **T04**: Teach `StatusTransitionService::getAvailableTransitions()` to read `currentStep.config.requiredFields[]` and treat it as an implicit `requiredField` guard on every exit transition from the current step's status. Reuse the existing user-facing message format `"Vereist veld ontbreekt: '<label>'"` — do NOT introduce a new message key.

- [ ] **T05**: Teach the step-completion handler in `StatusTransitionService` to append `currentStep.config.autoActions[]` to the existing automatic-action pipeline. Step-level auto-actions fire BEFORE transition-level auto-actions when the same event completes both. Per ADR, failures are logged via `$this->logger->error()` but do NOT roll back the status change.

- [ ] **T06**: Teach `TaskCreatorService::createForStep()` to stamp `task.dueAt` (computed from `step.config.sla`) and `task.escalationAt` (computed from `step.config.escalationRule.offset` relative to `dueAt`) at activation time. Absent `config.sla` leaves both fields null.

---

## Frontend: Editor

- [ ] **T07**: Create `src/views/settings/components/StepConfigPanel.vue`. Collapsible "Geavanceerd" panel rendered inside the existing per-step row of `WorkflowStepsEditor.vue` (do NOT add a separate dialog). Imports only from `@conduction/nextcloud-vue`. Sub-components: `SlaInput.vue`, `RequiredFieldsPicker.vue` (multi-select sourced from the linked caseType's field list), `AutoActionsList.vue` (sourced from the `automatic-actions` catalog endpoint), `EscalationRuleEditor.vue`. All strings via `this.t(appName, '...')`. SPDX header on line 1. All destructive sub-actions confirmed via `CnDialog`, never `window.confirm`.

- [ ] **T08**: Wire `StepConfigPanel.vue` into `WorkflowStepsEditor.vue` (already created by `workflow-definition-model`). Show the panel only when the parent definition is in `draft` lifecycle status. For `published` / `deprecated` definitions render the panel read-only.

---

## Migration

- [ ] **T09**: Implement repair step `lib/Migration/BackfillBezwaarStepConfig.php`. Loads the seeded bezwaar `workflowTemplate`, attaches the `config` block per known step id (mapping table in `design.md`), publishes a new version via `WorkflowDefinitionService::clone()` + `publish()`, and deprecates the previous active version. Idempotent: skips when the active version already has any step with a populated `config`. Removes the now-redundant SLA constants from `BezwaarLifecycleService.php`.

---

## Documentation

- [ ] **T10**: Update `openspec/specs/process-step-configuration/spec.md` to incorporate the eight new `REQ-PSC-*` requirements. Do NOT delete the two existing requirements; append the new ones after them. Update the spec's `status` frontmatter to `implemented` only after T01–T09 land.

---

## Pre-commit Verification

- [ ] **V01**: `grep -rL 'SPDX-License-Identifier' lib/Service/StepConfigValidator.php lib/Migration/BackfillBezwaarStepConfig.php src/views/settings/components/StepConfigPanel.vue src/views/settings/components/SlaInput.vue src/views/settings/components/RequiredFieldsPicker.vue src/views/settings/components/AutoActionsList.vue src/views/settings/components/EscalationRuleEditor.vue` → zero results.

- [ ] **V02**: `grep -rn 'getMessage()' lib/Service/StepConfigValidator.php lib/Service/StatusTransitionService.php` for the lines added by T03/T04/T05 → zero results. No raw exception messages in responses.

- [ ] **V03**: Manual QA — Publishing a draft with an unknown `autoActions[].key` is refused with a generic error. Editing the same draft to remove the bad key and re-publishing succeeds. A case in a status whose current step has `config.requiredFields: ["resultaat"]` shows transition buttons disabled until `resultaat` is filled, with the standard `"Vereist veld ontbreekt"` toast. A task created for a step with `config.sla: { value: 5, unit: businessDays }` has `dueAt` 5 working days in the future.
