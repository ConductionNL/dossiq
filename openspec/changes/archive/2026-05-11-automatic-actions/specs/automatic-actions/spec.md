---
status: draft
---
# Spec — automatic-actions: Declarative action registry for status transitions

## ADDED Requirements

### Requirement: REQ-AA-1 Declarative Action Definition Schema

The system SHALL persist automatic actions as `automaticAction` objects with `slug`, `type`, `tenantId`, `title`, `config`, and `isPublished` fields. The `slug` SHALL be unique within a tenant. The `type` SHALL be one of `sendEmail`, `createDocument`, `notifyRole`, `callWebhook`, `mergeTemplate`, `scheduleReminder`. The `config` shape SHALL be validated against the handler-specific schema for the chosen `type`.

**Feature tier**: V1

#### Scenario: Create a valid action

- **GIVEN** an admin authors a new action with `slug: "send-decision-email"`, `type: "sendEmail"`, `tenantId: <current>`, `title: "Verstuur besluit"`, `config: { recipientRef: "indiener", subjectTemplate: "Besluit {{case.identification}}", bodyTemplate: "..." }`
- **WHEN** they save via `POST /api/automatic-action`
- **THEN** the system SHALL persist an `automaticAction` object with `isPublished: false` (draft)
- **AND** subsequent `GET /api/automatic-action` calls in the same tenant SHALL include this action

#### Scenario: Reject duplicate slug

- **GIVEN** an action `send-decision-email` already exists in the current tenant
- **WHEN** an admin tries to create another action with the same `slug`
- **THEN** the controller SHALL return HTTP 409 with a static message
- **AND** the existing action SHALL remain unchanged

#### Scenario: Reject invalid type

- **WHEN** an admin submits an action with `type: "publishToLinkedIn"`
- **THEN** the controller SHALL return HTTP 422 with a static message
- **AND** NO `automaticAction` SHALL be persisted

---

### Requirement: REQ-AA-2 Action Registry with Slug Lookup

The system SHALL provide an `ActionRegistry` service that resolves a `(tenantId, slug)` pair to a published `automaticAction` object. Unpublished actions SHALL NOT be resolvable. Unknown slugs SHALL return `null` and be logged at error level. The registry SHALL maintain a per-request in-memory cache.

**Feature tier**: V1

#### Scenario: Resolve published action

- **GIVEN** an `automaticAction` with `slug: "send-decision-email"`, `isPublished: true`, `tenantId: T1`
- **WHEN** `ActionRegistry::resolve("T1", "send-decision-email")` is called
- **THEN** the registry SHALL return the full action object including `type` and `config`

#### Scenario: Unpublished action is not resolvable

- **GIVEN** an action `draft-webhook` with `isPublished: false`
- **WHEN** the registry resolves the slug
- **THEN** the registry SHALL return `null`
- **AND** an error SHALL be logged via `$this->logger->error()` with the slug AND the reason (`unpublished`)

---

### Requirement: REQ-AA-3 Built-In Action Handlers

The system SHALL provide six built-in `ActionHandlerInterface` implementations registered via the `procest.transition_side_effect_handler` DI tag: `sendEmail`, `createDocument`, `notifyRole`, `callWebhook`, `mergeTemplate`, and `scheduleReminder`. Each handler SHALL catch `\Throwable`, log via `$this->logger->error()` with full context, and return `ActionResult { ok: false, error: <static-message> }` on failure. Handlers SHALL NEVER include `$e->getMessage()` in `ActionResult.error`.

**Feature tier**: V1

#### Scenario: sendEmail handler renders templates and sends

- **GIVEN** a `sendEmail` action with `subjectTemplate: "Besluit {{case.identification}}"` and `bodyTemplate: "Beste {{case.indiener.naam}}"`
- **AND** a case with `identification: "ZK-2026-001"` and `indiener.naam: "J. Jansen"`
- **WHEN** the handler fires
- **THEN** the handler SHALL invoke `NotificatieService::sendEmail` with subject `"Besluit ZK-2026-001"` and body starting with `"Beste J. Jansen"`
- **AND** the handler SHALL return `ActionResult { ok: true }`

#### Scenario: createDocument handler attaches rendered file

- **GIVEN** a `createDocument` action with `templateSlug: "besluitbrief"` and `outputName: "Besluit-{{case.identification}}.pdf"`
- **WHEN** the handler fires for case `ZK-2026-001`
- **THEN** the handler SHALL render the template against the case, store the resulting file in the case folder, and link it to the case
- **AND** the handler SHALL return `ActionResult { ok: true, data: { documentId: <uuid> } }`

#### Scenario: callWebhook timeout returns ok:false without rolling back

- **GIVEN** a `callWebhook` action whose `urlSlug` resolves to `https://partner.example.org/hook`
- **WHEN** the partner endpoint does not respond within the configured timeout
- **THEN** the handler SHALL return `ActionResult { ok: false, error: "webhook_timeout" }`
- **AND** the error SHALL be logged with full context
- **AND** subsequent actions in the same transition SHALL still fire

---

### Requirement: REQ-AA-4 Admin UI to Attach Actions to Transitions

The system SHALL provide an admin UI that allows attaching, reordering, and detaching actions on a status transition without code changes. The UI SHALL list only `automaticAction` objects belonging to the current tenant with `isPublished: true`. Reordering SHALL be drag-and-drop and SHALL persist as the order of `transitions[].automaticActions[]` in the workflow template.

**Feature tier**: V1

#### Scenario: Attach an action to a transition

- **GIVEN** a published action `send-decision-email` exists in the current tenant
- **AND** the admin is editing transition `Afronden` in the workflow editor
- **WHEN** the admin selects `send-decision-email` from the slug-autocomplete in the "Acties" section and saves
- **THEN** the transition's `automaticActions[]` SHALL be persisted with a new entry `{ ref: "send-decision-email" }` at the end of the array

#### Scenario: Reorder actions on a transition

- **GIVEN** a transition with three attached actions in order A → B → C
- **WHEN** the admin drags C above A and saves
- **THEN** the persisted `automaticActions[]` SHALL be `[{ref: "C"}, {ref: "A"}, {ref: "B"}]`
- **AND** subsequent transition executions SHALL fire the actions in the new order

#### Scenario: Unpublished actions are hidden from the autocomplete

- **GIVEN** an action `draft-webhook` exists with `isPublished: false`
- **WHEN** the admin opens the slug-autocomplete in the transition editor
- **THEN** `draft-webhook` SHALL NOT appear in the suggestion list

---

### Requirement: REQ-AA-5 Engine Dispatch Hook Resolves Action References

The system SHALL extend `SideEffectDispatcher::dispatch` to recognise `{ ref: <slug> }` entries in `automaticActions[]` and resolve them via the `ActionRegistry` before invoking the handler. The dispatcher SHALL pass `$transitionContext['tenantId']` (derived from the case) to the registry. Inline action JSON entries SHALL remain backward-compatible.

**Feature tier**: V1

#### Scenario: Dispatcher resolves a reference

- **GIVEN** a transition with `automaticActions: [{ ref: "send-decision-email" }]`
- **AND** a case in tenant `T1`
- **WHEN** the transition fires
- **THEN** the dispatcher SHALL call `ActionRegistry::resolve("T1", "send-decision-email")`
- **AND** the dispatcher SHALL invoke the handler registered for the resolved `type`
- **AND** `statusRecord.dispatchedActions` SHALL include `{type: "sendEmail", ref: "send-decision-email", ok: true}`

#### Scenario: Unknown reference is recorded but does not abort

- **GIVEN** a transition with `automaticActions: [{ ref: "ghost-action" }, { ref: "send-decision-email" }]`
- **AND** `ghost-action` does NOT exist in the tenant
- **WHEN** the transition fires
- **THEN** the dispatcher SHALL record `{type: "unknown", ref: "ghost-action", ok: false, error: "unknown_action_ref"}` in `statusRecord.dispatchedActions`
- **AND** the dispatcher SHALL still resolve and fire `send-decision-email`

#### Scenario: Inline action JSON is still honoured

- **GIVEN** a transition with `automaticActions: [{ type: "webhook", url: "https://legacy.example.org/hook" }]` (no `ref` key)
- **WHEN** the transition fires
- **THEN** the dispatcher SHALL skip registry lookup
- **AND** the dispatcher SHALL invoke the `webhook` handler with the inline config directly

---

### Requirement: REQ-AA-6 Dry-Run Mode

The system SHALL support a dry-run mode that lets administrators preview an action's effect against a sample case without mutating live state. In dry-run, handlers SHALL compute and return their projected effect in `ActionResult.data` but SHALL NOT send mail, write documents, persist objects, hit webhook URLs, schedule jobs, or invoke any external services.

**Feature tier**: V1

#### Scenario: Dry-run sendEmail returns rendered preview

- **GIVEN** a `sendEmail` action with `subjectTemplate` and `bodyTemplate`
- **AND** a sample case `ZK-2026-001`
- **WHEN** an admin calls `POST /api/automatic-action/send-decision-email/dry-run` with `{caseId: "ZK-2026-001"}`
- **THEN** the response SHALL include the rendered `subject` and `body` in the body
- **AND** `NotificatieService::sendEmail` SHALL NOT be invoked
- **AND** NO email SHALL be delivered

#### Scenario: Dry-run callWebhook does not hit the URL

- **GIVEN** a `callWebhook` action with `urlSlug: "partner-hook"`
- **WHEN** an admin runs dry-run against a sample case
- **THEN** the response SHALL include the resolved URL and rendered payload
- **AND** NO outbound HTTP request SHALL be issued (verifiable via mocked `IClientService`)

---

### Requirement: REQ-AA-7 Per-Execution Audit on statusRecord

The system SHALL record one entry per dispatched action on `statusRecord.dispatchedActions` for every transition. Each entry SHALL include `type`, `ref` (when present), `ok`, and on failure a static `error` string. Successful entries MAY include handler-specific `data` (e.g. `messageId`, `documentId`). Audit entries SHALL be visible in the transition history endpoint provided by `status-transition-engine`.

**Feature tier**: V1

#### Scenario: Successful execution records ok

- **WHEN** a transition fires two actions and both succeed
- **THEN** `statusRecord.dispatchedActions` SHALL contain two entries with `ok: true`
- **AND** each entry SHALL include the original `ref` slug

#### Scenario: Failed execution records static error

- **WHEN** a `callWebhook` action returns HTTP 500
- **THEN** `statusRecord.dispatchedActions` SHALL contain an entry with `ok: false` and `error: "webhook_http_5xx"`
- **AND** the entry SHALL NOT include `$e->getMessage()` or any raw exception text

---

### Requirement: REQ-AA-8 Tenant Scoping and Cross-Tenant Isolation

The system SHALL scope every `automaticAction` object to a single `tenantId`. The registry, the admin UI, and the dispatcher SHALL refuse any cross-tenant lookup. Cross-tenant resolution attempts SHALL be logged via `$this->logger->error()` and surfaced to the dispatcher as `{ok: false, error: "unknown_action_ref"}` — the dispatcher SHALL NOT distinguish a cross-tenant miss from a non-existent slug in the user-visible response.

**Feature tier**: V1

#### Scenario: Cross-tenant resolution is rejected

- **GIVEN** an action `send-decision-email` exists in tenant `T1` (only)
- **AND** a case in tenant `T2` references the same slug via `{ ref: "send-decision-email" }`
- **WHEN** the transition fires
- **THEN** the registry SHALL return `null` for `(T2, "send-decision-email")`
- **AND** `statusRecord.dispatchedActions` SHALL record `{ok: false, error: "unknown_action_ref"}`
- **AND** an error SHALL be logged including both `T1` and `T2`

#### Scenario: Admin listing returns only own-tenant actions

- **GIVEN** tenant `T1` has 3 actions and tenant `T2` has 5 actions
- **WHEN** an admin in tenant `T2` calls `GET /api/automatic-action`
- **THEN** the response SHALL include exactly the 5 `T2` actions
- **AND** the response SHALL NOT include any `T1` action
