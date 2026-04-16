# Proposal: automatic-actions

## Summary

Implement the automatic action execution engine for workflow templates in Procest. When a case transitions between statuses or enters a workflow step, the engine evaluates configured automatic actions (`sendEmail`, `createTask`, `createSubCase`, `webhook`, `setField`, `notify`) and fires them without manual intervention — enabling no-code workflow automation for Dutch municipalities.

## Motivation

The `workflowTemplate` entity already carries `automaticActions` arrays on both steps and transitions, but no execution engine exists to trigger them at runtime. Workflow designers can define actions in the UI but nothing fires when cases move through statuses. This is a critical gap: the workflow builder feature has no runtime effect until this engine is in place.

Market demand confirms the priority: Webhooks API (demand 602), Workflow Integration (demand 593), Workflow automation with email alerts and field updates (demand 348), Business Process Management (demand 1420), and Workflow Automation (demand 302) all point to the same need for operational automation. The workflowTemplate schema supports all action types — this change delivers their execution.

## Affected Projects

- [ ] Project: `procest` — Add workflow action execution engine, per-type action handlers, webhook dispatcher, audit logging

## Scope

### In Scope (V1)

- **REQ-AUTO-001**: `sendEmail` action — send a templated email to configured recipients when a status transition fires
- **REQ-AUTO-002**: `createTask` action — auto-create a task on the case with configurable title, description, assignee role, and due date offset
- **REQ-AUTO-003**: `createSubCase` action — auto-create a child case of a configured case type, linked via `parentCase`
- **REQ-AUTO-004**: `webhook` action — POST a case payload in CloudEvents format to an external URL with retry on failure
- **REQ-AUTO-005**: `setField` action — update a field on the case object to a configured value (supports literal and template expressions)
- **REQ-AUTO-006**: `notify` action — send a Nextcloud in-app notification to configured users or roles
- **REQ-AUTO-007**: Audit logging — record every fired action and its result (success/failure) in the case audit trail
- **REQ-AUTO-008**: Template variable interpolation — action configs may reference case field values via `{{case.fieldName}}` expressions

### Out of Scope

- Visual workflow builder UI — separate change (workflow-builder)
- Scheduled/cron-based automatic actions (time-based triggers) — deferred to V2
- AI-driven action suggestion — part of ai-assisted-processing
- Guard evaluation — already implemented in workflow transition logic
- Inbound webhook processing (that is ZGW notificaties-api)

## Approach

1. **Backend**: New `WorkflowActionExecutor` PHP service that hooks into case status change events via an event listener on `CaseStatusChangedEvent`. On each transition the executor resolves the active `workflowTemplate` bound to the case, loads the matched transition's `automaticActions`, and dispatches each action to the appropriate handler.
2. **Handler pattern**: A common `ActionHandlerInterface` with separate implementations per action type: `SendEmailHandler`, `CreateTaskHandler`, `CreateSubCaseHandler`, `WebhookHandler`, `SetFieldHandler`, `NotifyHandler`. Each handler receives the action config and the case context.
3. **Webhook delivery**: Delegates to OpenRegister's `WebhookService` for delivery, retry logic, signature signing, and CloudEvents formatting — no custom HTTP client.
4. **Notifications**: Delegates to OpenRegister's `NotificationService` — no custom notification system.
5. **Object mutations**: Task creation and sub-case creation use `ObjectService.saveObject()` — no custom controllers needed for these.
6. **Audit**: Every action execution (fired, skipped, failed) is recorded via `AuditTrailService` on the case object — no new schema required.
7. **Frontend**: No new views. Fired action results appear in the existing case activity timeline. Configuration of actions lives in the workflow builder (separate change).

## Cross-Project Dependencies

- **OpenRegister** — `WebhookService` (webhook delivery + retry), `NotificationService` (in-app notifications), `WorkflowEngineRegistry` (event hook points), `ObjectService` (field updates, task/case creation), `AuditTrailService` (action execution logging)
- **n8n** (optional) — webhook actions may target n8n workflow trigger URLs for extended no-code automation
