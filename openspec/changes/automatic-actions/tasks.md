# Tasks: Automatic Actions

## Deduplication Check

- [ ] **D01**: Verify no overlap with existing Procest services — search `openspec/specs/` and `lib/Service/` for any existing `WorkflowActionExecutor`, `WebhookHandler`, `SendEmailHandler`, or `AutomaticAction` implementations. Document findings even if "no overlap found". Confirm that OpenRegister's `WebhookService` and `NotificationService` are reused rather than rebuilt.

## Implementation Tasks

### Backend: Event Infrastructure

- [ ] **T01**: Create `lib/Event/CaseStatusChangedEvent.php` — Symfony event class. Constructor: `string $caseId`, `string $oldStatusId`, `string $newStatusId`, `string $actorUserId`, `string $transitionId`. Getters for each property. Immutable (no setters). Extend `\Symfony\Contracts\EventDispatcher\Event`.

- [ ] **T02**: Modify `lib/Service/CaseService.php` — After `ObjectService.saveObject()` succeeds on a status update, dispatch `CaseStatusChangedEvent` via `IEventDispatcher::dispatchTyped()`. Pass the case ID, previous status ID (read from the case before update), new status ID, actor user ID, and matched transition ID (if resolved). If no workflow template is bound, dispatch with empty `transitionId`.

### Backend: Action Handler Interface and Registry

- [ ] **T03**: Create `lib/Service/WorkflowAction/ActionHandlerInterface.php` — Interface with single method: `handle(array $actionConfig, array $caseContext): ActionResult`. The `$caseContext` array includes: `caseId`, `caseObject` (full case array), `transitionId`, `stepId` (if step entry), `actorUserId`.

- [ ] **T04**: Create `lib/Service/WorkflowAction/ActionResult.php` — Value object with: `status` (enum: `success`, `failed`, `skipped`), `message` (string), `data` (array, optional). Named constructor methods: `ActionResult::success(string $message, array $data = [])`, `ActionResult::failed(string $message)`, `ActionResult::skipped(string $reason)`.

- [ ] **T05**: Create `lib/Service/WorkflowTemplateVariableResolver.php` — Service that accepts a config array and a case context array and returns the config with all `{{variable}}` expressions replaced. Supported variables: `{{case.<fieldName>}}` (any case property), `{{now}}` (today YYYY-MM-DD), `{{now+Nd}}` (today + N days, e.g. `{{now+14d}}`), `{{now-Nd}}` (today - N days). Unknown variables → empty string + log warning. All string values in the config array (recursive) are processed.

### Backend: Per-Type Action Handlers

- [ ] **T06**: Create `lib/Service/WorkflowAction/SendEmailHandler.php` — Implements `ActionHandlerInterface`. Required config keys: `to` (string), `subject` (string), `body` (string). Optional: `cc` (string), `bcc` (string). Injects Nextcloud Mailer (`IMailer`). `handle()`: (1) resolve `to` — if empty after interpolation return `ActionResult::skipped("Recipient resolved to empty")`, (2) build `IMessage` with `from` set to the app's configured sender, (3) send via `IMailer::send()`, (4) return `ActionResult::success("Email sent to <to>")`. Catch `\Exception`, return `ActionResult::failed($e->getMessage())`.

- [ ] **T07**: Create `lib/Service/WorkflowAction/CreateTaskHandler.php` — Implements `ActionHandlerInterface`. Required config keys: `title` (string). Optional: `description` (string), `assigneeRole` (string — roleType slug), `dueDateOffsetDays` (integer). Injects `ObjectService`. `handle()`: (1) resolve assignee by looking up case roles matching `assigneeRole` slug and taking the first participant's user ID, (2) compute `dueDate` as today + `dueDateOffsetDays` days, (3) call `ObjectService.saveObject()` with schema `task`, fields: `title`, `description`, `case` (case ID ref), `assignee`, `dueDate`, `status: "Open"`, (4) return `ActionResult::success("Task created: <taskId>", ["taskId" => $taskId])`.

- [ ] **T08**: Create `lib/Service/WorkflowAction/CreateSubCaseHandler.php` — Implements `ActionHandlerInterface`. Required config keys: `caseType` (string — caseType slug or UUID), `title` (string). Optional: `assignee` (string — user ID or `{{case.assignee}}`). Injects `ObjectService`. `handle()`: (1) resolve `caseType` object by slug — if not found return `ActionResult::failed("CaseType '<caseType>' not found")`, (2) find the caseType's first statusType (lowest `order`), (3) call `ObjectService.saveObject()` for schema `case` with: `title`, `caseType`, `parentCase` (parent case ID), `sourceOrganisation` (copied from parent), `status` (first statusType ID), `startDate` (today), (4) append new case ID to parent case's `relatedCases` array via `ObjectService.saveObject()`, (5) return `ActionResult::success("Sub-case created: <caseId>", ["caseId" => $caseId])`.

- [ ] **T09**: Create `lib/Service/WorkflowAction/WebhookHandler.php` — Implements `ActionHandlerInterface`. Required config keys: `url` (string). Optional: `method` (string, default `"POST"`), `secret` (string — HMAC signing key). Injects OpenRegister `WebhookService`. `handle()`: (1) if `url` is empty return `ActionResult::skipped("URL not configured")`, (2) build CloudEvents payload: `specversion: "1.0"`, `id: <uuid>`, `type: "nl.procest.case.status.changed"`, `source: "/procest/cases/<caseId>"`, `time: <now ISO 8601>`, `data: <caseObject>`, (3) if `secret` is set, compute `X-Procest-Signature: hmac-sha256=<HMAC-SHA256(payload, secret)>` and add to headers, (4) call `WebhookService->dispatch($url, $payload, $headers)`, (5) return `ActionResult::success("Webhook dispatched to <url>")`. On exception: return `ActionResult::failed($e->getMessage())`.

- [ ] **T10**: Create `lib/Service/WorkflowAction/SetFieldHandler.php` — Implements `ActionHandlerInterface`. Required config keys: `field` (string), `value` (string). Injects `ObjectService`. Read-only fields (reject with `ActionResult::failed`): `id`, `uuid`, `identifier`, `createdAt`. `handle()`: (1) load the case object, (2) check `field` is not in the read-only list, (3) patch the field value on the case data array, (4) call `ObjectService.saveObject()` with the patched case, (5) return `ActionResult::success("Field '<field>' set to '<value>'", ["oldValue" => $old, "newValue" => $value])`.

- [ ] **T11**: Create `lib/Service/WorkflowAction/NotifyHandler.php` — Implements `ActionHandlerInterface`. Config keys: `users` (array of user IDs, optional), `roles` (array of roleType slugs, optional), `message` (string). Injects OpenRegister `NotificationService` and `ObjectService`. `handle()`: (1) resolve recipients: start with `users` list, then look up all case role assignments matching any slug in `roles` and collect participant user IDs — deduplicate, (2) if recipient list is empty return `ActionResult::skipped("No users found for roles: <roles>")`, (3) for each recipient call `NotificationService->notify($userId, $message)`, (4) return `ActionResult::success("Notification sent to <count> user(s)")`.

### Backend: Executor and Orchestrator

- [ ] **T12**: Create `lib/EventListener/WorkflowActionExecutor.php` — Listens on `CaseStatusChangedEvent`. Injects `ObjectService`, `WorkflowTemplateVariableResolver`, and all 6 action handlers (keyed by action type string). `handle(CaseStatusChangedEvent $event)` method: (1) load the case from `ObjectService`, (2) if `case.workflowTemplate` is empty, return early, (3) load the `workflowTemplate` object, (4) decode `steps` and `transitions` JSON arrays, (5) find the matching transition by `transitionId` (from event) — if found, collect transition `automaticActions`, (6) find the matching step by the new status ID — if found, collect step `automaticActions`, (7) concatenate: transition actions first, then step actions, (8) for each action: run `WorkflowTemplateVariableResolver->resolve($actionConfig, $caseContext)`, dispatch to the matching handler, log result via `AuditTrailService` with actor `"system"`, continue on failure.

- [ ] **T13**: Register `WorkflowActionExecutor` as event listener in `appinfo/info.xml` — Add `<listener>` entry for `OCA\Procest\EventListener\WorkflowActionExecutor` listening on `OCA\Procest\Event\CaseStatusChangedEvent`. Priority 0 (no need for ordering relative to other listeners).

### Seed Data

- [ ] **T14**: Add 5 seed `workflowTemplate` objects to `lib/Settings/procest_register.json` — Use the objects defined in `design.md` (Seed Objects 1-5). Each uses `@self` envelope with `register: "procest"`, `schema: "workflowTemplate"`, and unique `slug`. Objects demonstrate all 6 action types across realistic Dutch municipal workflows: Omgevingsvergunning, Melding Openbare Ruimte, Bezwaar, Kapvergunning, and Subsidieaanvraag.

### Testing (Backend)

- [ ] **T15**: Create `tests/Unit/Service/WorkflowAction/SendEmailHandlerTest.php` — Test: (1) email sent when `to` is a valid address, (2) `ActionResult::skipped` when `to` resolves to empty, (3) template interpolation applied to `subject` and `body`, (4) `ActionResult::failed` on mailer exception.

- [ ] **T16**: Create `tests/Unit/Service/WorkflowAction/CreateTaskHandlerTest.php` — Test: (1) task object created with correct fields, (2) `dueDate` computed correctly from `dueDateOffsetDays`, (3) assignee resolved from role on case, (4) assignee left empty when no matching role, (5) title interpolated from case context.

- [ ] **T17**: Create `tests/Unit/Service/WorkflowAction/CreateSubCaseHandlerTest.php` — Test: (1) sub-case created with `parentCase` reference, (2) `sourceOrganisation` copied from parent, (3) `ActionResult::failed` when `caseType` not found, (4) first statusType assigned by lowest order.

- [ ] **T18**: Create `tests/Unit/Service/WorkflowAction/WebhookHandlerTest.php` — Test: (1) `WebhookService` called with correct CloudEvents payload, (2) HMAC signature header present when `secret` is configured, (3) `ActionResult::skipped` when URL is empty, (4) `ActionResult::failed` on dispatch exception.

- [ ] **T19**: Create `tests/Unit/Service/WorkflowAction/SetFieldHandlerTest.php` — Test: (1) case field updated via `ObjectService.saveObject()`, (2) `ActionResult::failed` for read-only field `identifier`, (3) `{{now}}` and `{{now+Nd}}` interpolation applied to value, (4) audit trail entry includes old and new value.

- [ ] **T20**: Create `tests/Unit/Service/WorkflowAction/NotifyHandlerTest.php` — Test: (1) `NotificationService` called for each explicit user, (2) roles resolved to participant user IDs, (3) `ActionResult::skipped` when no recipients found, (4) message template interpolated.

- [ ] **T21**: Create `tests/Unit/Service/WorkflowTemplateVariableResolverTest.php` — Test: (1) `{{case.identifier}}` resolved, (2) `{{now}}` returns today YYYY-MM-DD, (3) `{{now+14d}}` returns correct future date, (4) `{{now-7d}}` returns correct past date, (5) unknown variable returns empty string, (6) nested config arrays processed recursively.

- [ ] **T22**: Create `tests/Unit/EventListener/WorkflowActionExecutorTest.php` — Test: (1) all transition actions fired when transition ID matches, (2) step actions fired when new status matches step status, (3) transition actions fire before step actions, (4) failed action does not abort subsequent actions, (5) executor returns early when no workflow template bound to case.

## Verification Tasks

- [ ] **V01**: Configure a workflow template with a `sendEmail` action on a transition. Transition a case through that status. Verify the email is received and the audit trail shows `status: success`.
- [ ] **V02**: Configure a `createTask` action with `dueDateOffsetDays: 7`. Trigger the step. Verify a task appears on the case with the correct due date and assignee.
- [ ] **V03**: Configure a `createSubCase` action. Trigger the step. Verify the child case exists with `parentCase` set and the parent's `relatedCases` updated.
- [ ] **V04**: Configure a `webhook` action pointing to a test endpoint (e.g., requestbin). Trigger the transition. Verify the CloudEvents payload is received with correct `type` and `source`.
- [ ] **V05**: Configure a `setField` action with `field: "archiveNomination"`, `value: "bewaren"`. Trigger the transition. Verify the case field is updated.
- [ ] **V06**: Configure a `notify` action with `roles: ["behandelaar"]`. Assign a user to the `behandelaar` role on the case. Trigger the transition. Verify the Nextcloud notification appears for that user.
- [ ] **V07**: Trigger a transition where one action fails (e.g., webhook to unreachable URL). Verify the other actions in the same transition still fire. Verify the audit trail shows the failed action alongside successful ones.
- [ ] **V08**: Use `{{case.identifier}}` in an email subject. Trigger the transition. Verify the email subject contains the case identifier value.
- [ ] **V09**: Configure both transition-level and step-level `automaticActions`. Trigger the transition. Verify transition actions appear before step actions in the audit trail.
- [ ] **V10**: Trigger a case status change on a case with no bound `workflowTemplate`. Verify no actions fire and no errors appear in the Nextcloud log.
