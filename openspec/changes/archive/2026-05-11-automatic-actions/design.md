# Design: automatic-actions

## Architecture Overview

The automatic-actions registry sits between the static `transitions[].automaticActions[]` array (authored by `workflow-definition-model`) and the runtime `SideEffectDispatcher` (owned by `status-transition-engine`). Today the engine reads inline action JSON and invokes a hard-coded handler list; after this change, each `automaticActions[]` entry references an `automaticAction` object by `slug`, and the registry resolves the entry against a per-tenant catalogue before dispatch.

```
workflowTemplate.transitions[].automaticActions[]
    └── { ref: "send-decision-email" }                  ← reference
SideEffectDispatcher::dispatch()
    └── ActionRegistry::resolve(tenantId, ref)          ← lookup
        └── automaticAction { type, config, slug, ... } ← stored object
            └── ActionHandlerInterface registered for `type`
                ├── SendEmailHandler        (existing)
                ├── CreateDocumentHandler   (NEW)
                ├── NotifyRoleHandler       (NEW)
                ├── CallWebhookHandler      (existing — promoted)
                ├── MergeTemplateHandler    (NEW)
                └── ScheduleReminderHandler (NEW)
```

## Action Types

Six handler `type`s are supported in V1. Every type maps 1:1 to a registered `ActionHandlerInterface` implementation:

| Type | Purpose | Config keys (illustrative) |
|------|---------|----------------------------|
| `sendEmail` | Send a templated email | `recipientRef` (role / field), `subjectTemplate`, `bodyTemplate` |
| `createDocument` | Render a document template and attach to case | `templateSlug`, `outputName`, `mergeFields[]` |
| `notifyRole` | In-app Nextcloud notification to a role's members | `roleSlug`, `messageTemplate` |
| `callWebhook` | Outbound HTTP POST to an external URL | `urlSlug` (resolves via tenant secret store), `payloadTemplate`, `timeoutSec` |
| `mergeTemplate` | Render a text/markdown template into a case field | `templateSlug`, `targetField` |
| `scheduleReminder` | Enqueue a reminder for a future date | `offsetIso8601`, `recipientRef`, `messageTemplate` |

## Action Definition Schema

Each `automaticAction` object has:

- `slug` — tenant-unique identifier (e.g. `send-decision-email`).
- `type` — one of the six handler types above.
- `tenantId` — owning tenant (mandatory for isolation).
- `title` — admin-facing label.
- `description` — optional human description.
- `config` — handler-specific config object (matches handler's expected shape).
- `version` — optimistic-lock counter; updated on every save.
- `isPublished` — `false` while drafting; only `true` actions are dispatched.

The `transitions[].automaticActions[]` array stores **references**: `{ ref: <slug> }`. References are resolved at dispatch time so an admin can swap an action's body globally without touching every transition.

## Per-Tenant Action Library

Actions are stored as `automaticAction` objects in OpenRegister, scoped by `tenantId`. The admin UI ("Automatische acties") lists, filters, and edits all actions in the current tenant. `ActionRegistry::resolve(tenantId, slug)` rejects cross-tenant lookups with a static error logged via `$this->logger->error()` and returns `null` so the dispatcher records `{ok: false, error: 'unknown_action_ref'}`.

## Dry-Run Mode

Dry-run uses the same handler classes but with a **simulation flag** on `$transitionContext`. Handlers MUST honour the flag:

- `sendEmail` builds the rendered subject + body and returns them in `ActionResult.data`, but does NOT submit to `NotificatieService`.
- `createDocument` renders the merged template payload and returns the byte count, but does NOT persist a file.
- `callWebhook` resolves the URL, builds the payload, and returns both, but does NOT issue the HTTP request.
- `notifyRole`, `mergeTemplate`, and `scheduleReminder` likewise compute their projected effect without writing anywhere.

Dry-run is invoked via `POST /api/automatic-action/{slug}/dry-run` with a sample case payload. The endpoint is admin-only.

## Failure Model

Failure semantics inherit unchanged from `status-transition-engine`:

- Failed actions are recorded in `statusRecord.dispatchedActions[].error` as a static message.
- Status changes are NOT rolled back.
- Unknown action `ref`s record `{ok: false, error: 'unknown_action_ref'}`.
- Unpublished or cross-tenant `ref`s receive the same treatment.
