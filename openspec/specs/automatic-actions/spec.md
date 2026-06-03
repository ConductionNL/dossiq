---
retrofit_extensions:
  - REQ-001
  - REQ-002
  - REQ-003
  - REQ-004
  - REQ-005
---

## Purpose

@e2e exclude Automatic actions is V1; action execution is backend logic (n8n webhooks, email triggers) not testable via Playwright.

## Requirements

### Requirement: Automatic Action Framework

The system SHALL support configurable automatic actions that execute when a status transition occurs or a step is completed. Actions are defined as part of the workflow template and executed by the frontend or delegated to n8n webhooks.

**Feature tier**: V1

Action types:
- `sendEmail`: Send a templated email to a case participant
- `createTask`: Create a new task for a specified role
- `createSubCase`: Create a deelzaak of a specified type
- `webhook`: Call an n8n webhook URL with case data
- `setField`: Set a case property to a computed value
- `notify`: Create a Nextcloud notification for a user or role

#### Scenario: Configure email action on transition

- **WHEN** an administrator configures transition "Afhandelen" with action `sendEmail`
- **THEN** the configuration panel SHALL allow setting: recipient (role or specific field), email template, subject template
- **AND** templates SHALL support variable substitution: `{{case.title}}`, `{{case.handler}}`, `{{transition.label}}`

#### Scenario: Email action executes on transition

- **WHEN** transition "Afhandelen" is executed on case "ZK-2024-001"
- **AND** the transition has a `sendEmail` action configured for the "zaakklant" role
- **THEN** the system SHALL send the templated email to the email address of the user with role "zaakklant" on the case

#### Scenario: Task creation action on step completion

- **WHEN** step "Toets ontvankelijkheid" is completed
- **AND** the step has a `createTask` action configured for role "Vakspecialist"
- **THEN** the system SHALL create a new task with the configured title and description, assigned to role "Vakspecialist"

#### Scenario: Webhook action delegates to n8n

- **WHEN** a transition has a `webhook` action configured with URL `https://n8n.example.com/webhook/abc123`
- **AND** the transition is executed
- **THEN** the system SHALL POST the case data (id, title, status, transition details) to the webhook URL
- **AND** the webhook response SHALL be logged but SHALL NOT block the transition

### Requirement: Action Execution Error Handling

The system SHALL handle action execution failures gracefully without rolling back the transition.

**Feature tier**: V1

#### Scenario: Email action fails

- **WHEN** a `sendEmail` action fails (SMTP error, invalid recipient)
- **THEN** the status transition SHALL still complete successfully
- **AND** the error SHALL be logged in the case audit trail
- **AND** a warning notification SHALL be created for the case handler: "Automatische e-mail kon niet worden verzonden"

#### Scenario: Webhook action times out

- **WHEN** a `webhook` action does not respond within 10 seconds
- **THEN** the action SHALL be marked as failed in the audit trail
- **AND** the transition SHALL still complete successfully

<!-- BEGIN retrofit-2026-05-24-automatic-actions -->

### REQ-001: Action handlers SHALL implement a common contract

Every automatic-action handler SHALL implement `OCA\Procest\Service\Actions\ActionHandlerInterface`, exposing a `type(): string` discriminator (matching the `actionConfig.type` value in the workflow definition) and a `handle(array $actionConfig, array $case, array $transitionContext): ActionResult` method. The handler SHALL be stateless across invocations — all per-invocation state is passed via the three method arguments — and SHALL return a `ActionResult` value object containing success flag, output payload, and optional error message via `toArray()`.

#### Scenario: Handler routed by type
- **GIVEN** a workflow defines an action with `type: "sendEmail"`
- **WHEN** the action runner asks `ActionRegistry::get('sendEmail')` for a handler
- **THEN** an instance of `SendEmailHandler` SHALL be returned, whose `type()` SHALL equal `'sendEmail'`

### REQ-002: ActionRegistry SHALL provide handler lookup and listing for admin UI

`OCA\Procest\Service\Actions\ActionRegistry` SHALL accept all 7 built-in handler implementations via constructor injection, expose a `get(string $type): ActionHandlerInterface` lookup that throws when no handler matches the discriminator, and expose a `list()` method returning the available handler types for the admin Automatic-Actions settings page (`/settings/automatic-actions`). The registry SHALL be read-only — handler set is fixed at boot.

#### Scenario: Unknown action type
- **WHEN** `ActionRegistry::get('unknown-type')` is called
- **THEN** the registry SHALL throw with an error identifying the missing handler type

### REQ-003: Notification-family handlers SHALL deliver outbound messages

`SendEmailHandler` (type `sendEmail`), `CallWebhookHandler` (type `callWebhook`) and `NotifyRoleHandler` (type `notifyRole`) SHALL each accept their respective `actionConfig` shape and dispatch an outbound message: email via `NotificatieService::sendEmail()`, HTTP POST to a configured URL, or in-product notification to all users currently assigned to a role on the case. Failures SHALL be logged with full context and returned as a static failure `ActionResult` rather than thrown — the transition SHALL still complete.

#### Scenario: Webhook timeout does not fail the transition
- **GIVEN** a `callWebhook` action whose target URL does not respond within the configured timeout
- **WHEN** `CallWebhookHandler::handle(...)` runs
- **THEN** the returned `ActionResult` SHALL have `success: false` and the transition SHALL continue to its next action

#### Scenario: Notify all users in role
- **GIVEN** a case with role `behandelaar` resolving to two users
- **WHEN** `NotifyRoleHandler::handle({type: 'notifyRole', role: 'behandelaar', message: '...'}, $case, $ctx)` runs
- **THEN** both users SHALL receive an in-product notification

### REQ-004: Content-family handlers SHALL render templates against the case

`CreateDocumentHandler` (type `createDocument`) and `MergeTemplateHandler` (type `mergeTemplate`) SHALL render template content against the case payload using the shared `HandlesTemplates` trait. `CreateDocumentHandler` SHALL persist the rendered content as a case-attached document via the OpenRegister files-attached-to-object mechanism; `MergeTemplateHandler` SHALL return the rendered content as the `ActionResult.output` payload so a subsequent action can consume it. Template lookup SHALL accept either an inline body string or a `template` reference into the templates register.

#### Scenario: Create a document from a template
- **WHEN** `CreateDocumentHandler::handle({type: 'createDocument', template: '<id>', filename: 'besluit.pdf'}, $case, $ctx)` runs
- **THEN** the rendered file SHALL be attached to the case and the returned `ActionResult.output` SHALL contain the new document's UUID

### REQ-005: ScheduleReminderHandler SHALL defer execution via a Nextcloud BackgroundJob

`ScheduleReminderHandler` (type `scheduleReminder`) SHALL accept `{type: 'scheduleReminder', triggerAt: <ISO8601>, action: <ActionConfig>}` and register a deferred-execution BackgroundJob (the deferred reminder job class is owned by status-transition-engine and is referenced by FQCN). When the BackgroundJob fires, the wrapped `action` SHALL be re-dispatched through `ActionRegistry` for normal execution. The handler SHALL return an `ActionResult.success: true` immediately upon scheduling, regardless of the wrapped action's eventual outcome.

#### Scenario: Schedule a reminder for 24h later
- **WHEN** `ScheduleReminderHandler::handle({type: 'scheduleReminder', triggerAt: '<24h from now>', action: {type: 'sendEmail', ...}}, $case, $ctx)` runs
- **THEN** a deferred BackgroundJob SHALL be registered in Nextcloud's job queue
- **AND** the immediate `ActionResult` SHALL be `success: true`
- **AND** when the job fires later, `ActionRegistry::get('sendEmail')->handle(...)` SHALL execute with the original case payload

Notes
- `lib/Service/Transitions/SendEmailHandler.php` (separate file) is a parallel implementation for the status-transition-engine framework and is NOT part of this cluster.

<!-- END retrofit-2026-05-24-automatic-actions -->
