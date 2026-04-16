---
status: proposed
---
# automatic-actions Specification

## Purpose

Execute automatic actions defined in workflow templates when cases transition between statuses or enter workflow steps. The engine fires configured actions (`sendEmail`, `createTask`, `createSubCase`, `webhook`, `setField`, `notify`) without manual intervention, enabling no-code process automation for Dutch municipal case management.

## Context

The `workflowTemplate` entity in Procest already supports defining `automaticActions` arrays on both workflow steps and transitions. These action definitions reference six action types with typed config objects. However, no execution engine exists to fire them at runtime — workflow designers can configure actions in the UI but nothing happens when cases move through statuses.

This spec defines the behavior of the `WorkflowActionExecutor` and its per-type handlers. Each requirement maps to one action type or one cross-cutting concern (audit, variable interpolation, error handling). The implementation leverages OpenRegister's built-in `WebhookService`, `NotificationService`, and `ObjectService` rather than building custom infrastructure.

## Requirements

### REQ-AUTO-001: sendEmail action fires on status transition

When a case transitions to a new status and the matched workflow transition has a `sendEmail` action configured, the system MUST send an email to the configured recipient.

#### Scenario 1.1: Email sent on transition with literal recipient

- GIVEN case `zaak-2026-0042` (type: `omgevingsvergunning`) transitions from status `ontvangen` to `in-behandeling`
- AND the active workflow template's matching transition has a `sendEmail` action with `to: "behandelaar@gemeente-voorbeeld.nl"`, `subject: "Zaak in behandeling"`, `body: "Uw aanvraag is in behandeling genomen."`
- WHEN the status update is persisted
- THEN the system MUST send one email to `behandelaar@gemeente-voorbeeld.nl`
- AND the email subject MUST be `"Zaak in behandeling"`
- AND the case audit trail MUST record a `sendEmail` action entry with `status: success`

#### Scenario 1.2: Email sent to address resolved from case field

- GIVEN the `sendEmail` action config has `to: "{{case.communicationChannel}}"`
- AND the case's `communicationChannel` field is `"aanvrager@bedrijf.nl"`
- WHEN the action fires
- THEN the email MUST be sent to `aanvrager@bedrijf.nl`

#### Scenario 1.3: Email skipped when recipient resolves to empty

- GIVEN the `sendEmail` action config has `to: "{{case.communicationChannel}}"`
- AND the case has no `communicationChannel` set
- WHEN the action fires
- THEN the email MUST NOT be sent
- AND the case audit trail MUST record a `sendEmail` action entry with `status: skipped` and reason `"Recipient resolved to empty"`

#### Scenario 1.4: Email body supports template variable interpolation

- GIVEN a `sendEmail` action with `body: "Uw aanvraag {{case.identifier}} is ontvangen op {{now}}."`
- AND the case identifier is `"2026-0042"`
- AND today's date is `2026-04-16`
- WHEN the action fires
- THEN the email body MUST be `"Uw aanvraag 2026-0042 is ontvangen op 2026-04-16."`

---

### REQ-AUTO-002: createTask action auto-creates a task on the case

When a workflow step is entered or a transition fires and has a `createTask` action configured, the system MUST create a new `task` object linked to the case.

#### Scenario 2.1: Task created with title, description, and due date offset

- GIVEN case `zaak-2026-0101` transitions to step `In behandeling`
- AND the step has a `createTask` action with `title: "Controleer volledigheid"`, `description: "Controleer alle documenten"`, `dueDateOffsetDays: 5`
- WHEN the step is entered
- THEN a new `task` object MUST be created in OpenRegister with:
  - `title: "Controleer volledigheid"`
  - `description: "Controleer alle documenten"`
  - `case: "zaak-2026-0101"` (reference)
  - `dueDate: <today + 5 days>`
  - `status: "Open"`
- AND the case audit trail MUST record a `createTask` action entry with `status: success` and the new task ID

#### Scenario 2.2: Task assignee resolved from case assignee role

- GIVEN the `createTask` action config has `assigneeRole: "behandelaar"`
- AND the case has a role assignment linking a user to the `behandelaar` role type
- WHEN the action fires
- THEN the created task's `assignee` field MUST be set to the Nextcloud user ID of the `behandelaar` role participant
- AND if no user has the `behandelaar` role on the case, the `assignee` field MUST be left empty

#### Scenario 2.3: Task title supports template variable interpolation

- GIVEN a `createTask` action with `title: "Inspecteer locatie voor {{case.identifier}}"`
- AND the case identifier is `"2026-0101"`
- WHEN the action fires
- THEN the created task title MUST be `"Inspecteer locatie voor 2026-0101"`

#### Scenario 2.4: Duplicate task prevention — same action not fired twice

- GIVEN a status transition fires and creates a task
- WHEN the same transition fires again (e.g., case is reverted and re-transitioned)
- THEN the system MUST create a new task (idempotency is NOT enforced — each transition fire creates a new task)

---

### REQ-AUTO-003: createSubCase action auto-creates a child case

When a workflow step or transition has a `createSubCase` action configured, the system MUST create a new child `case` object linked via `parentCase`.

#### Scenario 3.1: Sub-case created with configured case type

- GIVEN case `zaak-2026-0200` (type: `bezwaar`) enters step `Hoorzitting`
- AND the step has a `createSubCase` action with `caseType: "hoorzitting-organisatie"`, `title: "Hoorzitting voor bezwaar {{case.identifier}}"`
- WHEN the step is entered
- THEN a new `case` object MUST be created with:
  - `caseType` pointing to the `hoorzitting-organisatie` case type
  - `title: "Hoorzitting voor bezwaar 2026-0200"`
  - `parentCase` referencing `zaak-2026-0200`
  - `status` set to the first `statusType` of the `hoorzitting-organisatie` case type (ordered by `order ASC`)
- AND the new case MUST appear in the parent case's `relatedCases` array
- AND the audit trail of the parent case MUST record a `createSubCase` entry with the child case ID

#### Scenario 3.2: Sub-case creation skipped if case type not found

- GIVEN the `createSubCase` action config references a `caseType` slug that does not exist in the register
- WHEN the action fires
- THEN no sub-case MUST be created
- AND the audit trail MUST record `status: failed` with reason `"CaseType 'hoorzitting-organisatie' not found"`
- AND the parent case status transition MUST still complete successfully (action failure does not roll back the transition)

#### Scenario 3.3: Sub-case inherits sourceOrganisation from parent

- GIVEN a parent case with `sourceOrganisation: "001234567"`
- WHEN a `createSubCase` action fires
- THEN the child case MUST have `sourceOrganisation: "001234567"` copied from the parent

---

### REQ-AUTO-004: webhook action dispatches HTTP POST to external URL

When a workflow step or transition has a `webhook` action configured, the system MUST POST a CloudEvents-formatted payload to the configured URL.

#### Scenario 4.1: Webhook posted on transition

- GIVEN case `zaak-2026-0303` transitions from `in-behandeling` to `besluit`
- AND the transition has a `webhook` action with `url: "https://extern-systeem.nl/api/webhook"`, `method: "POST"`
- WHEN the transition fires
- THEN the system MUST invoke OpenRegister's `WebhookService` to POST to `https://extern-systeem.nl/api/webhook`
- AND the request body MUST be a valid CloudEvents v1.0 JSON payload including:
  - `specversion: "1.0"`
  - `type: "nl.procest.case.status.changed"`
  - `source: "/procest/cases/zaak-2026-0303"`
  - `data`: the full case object payload
- AND the audit trail MUST record `status: success` with HTTP response code

#### Scenario 4.2: Webhook retry on delivery failure

- GIVEN a `webhook` action fires and the external endpoint returns HTTP 503
- WHEN the delivery fails
- THEN OpenRegister's `WebhookService` retry mechanism MUST handle the retry (exponential backoff, configurable max attempts)
- AND the action audit entry MUST record `status: pending_retry` until the webhook is successfully delivered or max retries are exhausted
- AND after max retries the audit entry MUST be updated to `status: failed`

#### Scenario 4.3: Webhook skipped when URL resolves to empty

- GIVEN the `webhook` action config has an empty `url` field
- WHEN the action fires
- THEN the webhook MUST NOT be dispatched
- AND the audit trail MUST record `status: skipped` with reason `"URL not configured"`

#### Scenario 4.4: Webhook signed with HMAC when secret is configured

- GIVEN the webhook action config includes `secret: "geheim-123"`
- WHEN the webhook is dispatched
- THEN the request MUST include a `X-Procest-Signature` header containing an HMAC-SHA256 signature of the payload body using the configured secret
- AND the receiving system can verify the signature using the same algorithm

---

### REQ-AUTO-005: setField action updates a field on the case object

When a workflow step or transition has a `setField` action configured, the system MUST update the specified field on the case to the configured value.

#### Scenario 5.1: Literal field value set on transition

- GIVEN case `zaak-2026-0404` transitions to status `Afgehandeld`
- AND the transition has a `setField` action with `field: "archiveNomination"`, `value: "bewaren"`
- WHEN the transition fires
- THEN the case's `archiveNomination` field MUST be updated to `"bewaren"` via `ObjectService.saveObject()`
- AND the case audit trail MUST record a `setField` action entry with the old and new values

#### Scenario 5.2: Template expression resolved before field update

- GIVEN a `setField` action with `field: "endDate"`, `value: "{{now}}"`
- AND today's date is `2026-04-16`
- WHEN the action fires
- THEN the case `endDate` MUST be set to `"2026-04-16"`

#### Scenario 5.3: Field update skipped for read-only or unknown field

- GIVEN a `setField` action with `field: "identifier"` (auto-generated, read-only)
- WHEN the action fires
- THEN the field update MUST be rejected
- AND the audit trail MUST record `status: failed` with reason `"Field 'identifier' is read-only"`
- AND the case status transition MUST still complete

#### Scenario 5.4: Numeric offset date expression for deadline

- GIVEN a `setField` action with `field: "plannedEndDate"`, `value: "{{now+30d}}"`
- AND today is `2026-04-16`
- WHEN the action fires
- THEN `plannedEndDate` MUST be set to `"2026-05-16"`

---

### REQ-AUTO-006: notify action sends Nextcloud in-app notification

When a workflow step or transition has a `notify` action configured, the system MUST send Nextcloud in-app notifications to the configured users or role participants.

#### Scenario 6.1: Notification sent to explicitly listed users

- GIVEN a `notify` action with `users: ["user-jvandenberg", "user-pjanssen"]`, `message: "Zaak {{case.identifier}} vereist uw aandacht."`
- AND the case identifier is `"2026-0505"`
- WHEN the action fires
- THEN OpenRegister's `NotificationService` MUST send a Nextcloud notification to both `user-jvandenberg` and `user-pjanssen`
- AND the notification message MUST be `"Zaak 2026-0505 vereist uw aandacht."`

#### Scenario 6.2: Notification sent to all users with a specified role on the case

- GIVEN a `notify` action with `roles: ["behandelaar", "adviseur"]`
- AND the case has a `behandelaar` role assigned to `user-mdevries` and an `adviseur` role assigned to `user-kbakker`
- WHEN the action fires
- THEN notifications MUST be sent to both `user-mdevries` and `user-kbakker`

#### Scenario 6.3: Notification skipped when no matching role participants found

- GIVEN a `notify` action with `roles: ["commissielid"]`
- AND the case has no role assignments for `commissielid`
- WHEN the action fires
- THEN no notification is sent
- AND the audit trail MUST record `status: skipped` with reason `"No users found for roles: commissielid"`

---

### REQ-AUTO-007: Action execution audit trail

Every automatic action execution MUST be recorded in the case audit trail.

#### Scenario 7.1: Successful action recorded with outcome

- GIVEN any automatic action fires and completes successfully
- WHEN the action execution finishes
- THEN an audit trail entry MUST be appended to the case via `AuditTrailService` with:
  - `action: "automaticAction"`
  - `actionType: "<sendEmail|createTask|createSubCase|webhook|setField|notify>"`
  - `status: "success"`
  - `timestamp`: ISO 8601 datetime of execution
  - `triggeredBy: "workflow:transition:<transitionId>"` or `"workflow:step:<stepId>"`
  - `actor: "system"` (automated action, not a user)

#### Scenario 7.2: Failed action recorded without aborting transition

- GIVEN an automatic action throws an exception during execution (e.g., external webhook timeout)
- WHEN the exception is caught by the `WorkflowActionExecutor`
- THEN the case status transition MUST still be persisted
- AND subsequent actions in the same `automaticActions` array MUST still be attempted
- AND the failed action MUST be recorded in the audit trail with `status: "failed"` and the exception message

#### Scenario 7.3: Multiple actions in sequence — all logged individually

- GIVEN a transition has 3 `automaticActions`: `sendEmail`, `createTask`, `webhook`
- WHEN the transition fires
- THEN 3 separate audit trail entries MUST be recorded — one per action
- AND each entry MUST include its individual `status` (success/failed/skipped)

---

### REQ-AUTO-008: Template variable interpolation

Action config strings containing `{{variable}}` expressions MUST be resolved against the case context before the action handler receives them.

#### Scenario 8.1: Case field variable resolved

- GIVEN any action config containing `"{{case.title}}"`
- AND the case has `title: "Aanvraag vergunning Keizersgracht 123"`
- WHEN the `WorkflowTemplateVariableResolver` processes the config
- THEN `"{{case.title}}"` MUST be replaced with `"Aanvraag vergunning Keizersgracht 123"`

#### Scenario 8.2: Now variable resolved to today's date

- GIVEN any action config containing `"{{now}}"`
- WHEN the resolver processes the config
- THEN `"{{now}}"` MUST be replaced with today's date in `YYYY-MM-DD` format

#### Scenario 8.3: Date offset expression resolved

- GIVEN any action config containing `"{{now+14d}}"`
- AND today is `2026-04-16`
- WHEN the resolver processes the config
- THEN `"{{now+14d}}"` MUST be replaced with `"2026-04-30"`

#### Scenario 8.4: Unknown variable left as empty string

- GIVEN any action config containing `"{{case.nonExistentField}}"`
- WHEN the resolver processes the config
- THEN `"{{case.nonExistentField}}"` MUST be replaced with an empty string `""`
- AND a warning MUST be logged: `"Variable 'case.nonExistentField' not found in case context"`

---

### REQ-AUTO-009: Actions fire for both transitions and step entry

Automatic actions MUST be supported on both transition definitions and step definitions in the workflow template.

#### Scenario 9.1: Step entry actions fire when step status is first reached

- GIVEN a workflow step `"In behandeling"` has `automaticActions: [{type: "createTask", ...}]` at the step level
- AND case `zaak-2026-0606` transitions to the status referenced by that step
- WHEN the new status matches the step's `status` field
- THEN the step-level `automaticActions` MUST fire
- AND transition-level `automaticActions` on the same transition MUST also fire (both fire)

#### Scenario 9.2: Transition actions fire before step actions

- GIVEN a transition has `automaticActions: [actionA]` and the target step has `automaticActions: [actionB]`
- WHEN the transition is taken
- THEN `actionA` MUST fire first, then `actionB`
- AND both MUST be recorded in the audit trail in order
