# Tasks: automatic-actions

## Deduplication Check

- [ ] **D01**: Inventory existing handlers in `lib/Service/Transitions/` (from `status-transition-engine`): confirm `SendEmailHandler`, `CallWebhookHandler` (named `WebhookHandler` in the engine) and `NotifyHandler` already exist. This spec MUST extend them rather than duplicate. Verify there is no existing `ActionRegistry`, `automaticAction` schema, or per-tenant action library. Document findings here.

---

## Schema & Configuration

- [ ] **T01**: Add `automaticAction` schema to `lib/Settings/procest_register.json`. Required fields: `slug` (string), `type` (enum: `sendEmail`, `createDocument`, `notifyRole`, `callWebhook`, `mergeTemplate`, `scheduleReminder`), `tenantId` (string, format uuid), `title` (string), `config` (object). Optional fields: `description` (string), `version` (integer, default 1), `isPublished` (boolean, default false). Unique constraint on (`tenantId`, `slug`). Bump schema package version.

- [ ] **T02**: Add `automatic_action_schema` config key to `lib/Service/SettingsService.php` and load it in `initializeStores()`.

---

## Backend: Registry & Handlers

- [ ] **T03**: Create `lib/Service/Actions/ActionRegistry.php`. Methods:
  - `resolve(string $tenantId, string $slug): ?array` — fetch the matching `automaticAction` object scoped to `tenantId` AND `isPublished: true`; per-request cache; returns `null` and logs via `$this->logger->error()` on miss or cross-tenant attempt.
  - `listForTenant(string $tenantId, ?string $typeFilter = null): array` — admin listing endpoint backing.

- [ ] **T04**: Implement three new `ActionHandlerInterface` classes in `lib/Service/Actions/`:
  - `CreateDocumentHandler.php` — renders `templateSlug` against the case + `mergeFields[]` and attaches to the case via existing document service.
  - `NotifyRoleHandler.php` — resolves `roleSlug` to its members and emits an in-app notification to each via `NotificatieService`.
  - `MergeTemplateHandler.php` — renders a text/markdown template into `targetField` on the case via `ObjectService::saveObject` (3-arg API).
  - `ScheduleReminderHandler.php` — enqueues a Nextcloud background job for `offsetIso8601` from now; the job emits a notification on fire.
  All handlers MUST honour the `$transitionContext['dryRun'] === true` flag — they MUST compute the projected effect, return it in `ActionResult.data`, and NOT touch live state. All handlers catch `\Throwable`, log via `$this->logger->error()`, return `ActionResult { ok: false, error: <static-message> }` — NEVER bubble exceptions, NEVER include `$e->getMessage()` in `ActionResult.error`.

- [ ] **T05**: Promote the engine's existing `WebhookHandler` to `CallWebhookHandler` semantics (URL slug indirection — `urlSlug` resolves via the tenant secret store, not an inline URL). Add dry-run support: when `dryRun: true`, return the resolved URL and payload in `ActionResult.data` and SKIP the HTTP request. Existing `webhook` action types remain backward-compatible (inline URL still works), but admin-authored actions MUST use `urlSlug`.

- [ ] **T06**: Extend `lib/Service/Transitions/SideEffectDispatcher.php` (owned by `status-transition-engine`) to recognise `{ ref: <slug> }` entries in the `automaticActions[]` array. Resolution flow: detect ref-shape, call `ActionRegistry::resolve($tenantId, $slug)`, expand to the full action config, then continue with the existing per-action handler dispatch. Inline action JSON entries (legacy) MUST keep working. Tenant scoping derives `$tenantId` from `$transitionContext['tenantId']`, which the engine MUST set from the current case's tenant.

---

## Backend: Controller

- [ ] **T07**: Create `lib/Controller/AutomaticActionController.php`. Endpoints:
  - `GET    /api/automatic-action` — list this tenant's actions; optional `?type=` filter.
  - `POST   /api/automatic-action` — create.
  - `PATCH  /api/automatic-action/{slug}` — update (admin only).
  - `POST   /api/automatic-action/{slug}/publish` — flip `isPublished` to `true`.
  - `POST   /api/automatic-action/{slug}/dry-run` — body `{caseId | sampleCase}`; returns the handler's `ActionResult.data` without mutating state.
  - Register routes in `appinfo/routes.php`. All identity from `IUserSession`. ALL error responses static — NEVER `$e->getMessage()` in `JSONResponse`.

---

## Frontend

- [ ] **T08**: Register `automatic-action` entity in `src/store/store.js` via `createObjectStore('automatic-action')`. Kebab-case. Register ONCE.

- [ ] **T09**: Create `src/views/admin/AutomaticActions.vue` listing all actions for the current tenant with type filter, slug search, and publish-state badge. Provide "Nieuwe actie" wizard that picks `type` (6 options), shows the type-specific config form, and lets the admin run a dry-run preview before saving.

- [ ] **T10**: Embed an "Acties" section in `src/views/admin/TransitionEditor.vue` (provided by `workflow-definition-model`'s admin UI): list `automaticActions[]` for the selected transition, allow reordering (drag-handle), and let the admin pick from the published actions in the current tenant via a slug-autocomplete. Saving writes `{ ref: <slug> }` entries into the transition.

---

## Verification

- [ ] **V01**: All REQ-AA-1..8 in `openspec/changes/automatic-actions/specs/automatic-actions/spec.md` map 1:1 to implementation: schema (T01), registry (T03), handlers (T04, T05), dispatch hook (T06), admin UI (T09, T10), dry-run (T04 flag + T07 endpoint), tenant scoping (T03 + T06 context propagation).

- [ ] **V02**: PHPUnit covers, at minimum: registry resolves a published action; rejects unpublished; rejects cross-tenant; each new handler's happy path AND dry-run path (mutation MUST NOT occur in dry-run); dispatcher correctly resolves `{ ref }` entries AND preserves inline-action backward-compat; failure of any single handler does NOT roll back the status change. `composer check:strict` and `composer test` pass.

- [ ] **V03**: Browser smoke test via Playwright MCP (`browser-1`): admin creates a `sendEmail` action, runs dry-run (observe rendered subject + body, no email sent), publishes it, attaches it to a transition, executes the transition on a test case, and observes the email actually sent AND `statusRecord.dispatchedActions[0].ok = true`.
