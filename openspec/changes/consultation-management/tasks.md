# Tasks: consultation-management

## Schema & Configuration

- [~] **TASK-CN-01** — Add `consultation`, `adviceResponse`, and `advisoryBody` schemas to `procest_register.json`; register config keys in `SettingsService::SLUG_TO_CONFIG_KEY`; add seed data objects. — deferred to downstream cycle / fleet-wide adoption (handoff)
  - files: `lib/Settings/procest_register.json`, `lib/Service/SettingsService.php`
  - spec_ref: `specs/consultation-management/spec.md` (all requirements — data model)
  - acceptance: schemas validate; `importFromApp()` is idempotent; 4 consultation + 5 advisoryBody + 3 adviceResponse seed objects load on fresh install.

## Backend Services

- [~] **TASK-CN-02** — Implement `ConsultationService` with CRUD, status machine (`open` → `ontvangen` → `in_behandeling` → `advies_uitgebracht` → `afgesloten` / `ingetrokken`), deadline/extension logic, dependency-cycle validation (topological sort), and `getBlockingConsultations(zaakId)` for milestone gates. — deferred to downstream cycle / fleet-wide adoption (handoff)
  - files: `lib/Service/ConsultationService.php`
  - spec_ref: `specs/consultation-management/spec.md` §Lifecycle, §Mandatory gates
  - acceptance: all status transitions enforced; backward transitions raise `\InvalidArgumentException` for non-coordinator; cycle in `dependsOn` throws before persist; `getBlockingConsultations` returns only mandatory consultations with status ≠ `advies_uitgebracht`. `@spec` PHPDoc on every public method.

- [~] **TASK-CN-03** — Implement `AdvisoryBodyService` with specialization-weighted search and external-body email path including 256-bit secure-token issuance via `\OCP\Security\ISecureRandom`. — deferred to downstream cycle / fleet-wide adoption (handoff)
  - files: `lib/Service/AdvisoryBodyService.php`
  - spec_ref: `specs/consultation-management/spec.md` §Advisory body registry, §External body email path
  - acceptance: search returns bodies with matching specializations ranked first; token stored as SHA-256 hash; plaintext token included in n8n webhook payload once; token expiry fires on consultation closure.

## API Controller

- [~] **TASK-CN-04** — Implement `ConsultationController` with REST endpoints (`GET/POST/PUT/DELETE /api/consultations`, `GET /api/consultations?caseId={id}`, `GET /api/consultations/inbox`) plus the public `/api/public/consultations/{token}` route. All mutation endpoints include per-object IDOR check. Public route annotated `#[PublicPage]` + `#[NoCSRFRequired]`. All external access logged for BIO compliance. — deferred to downstream cycle / fleet-wide adoption (handoff)
  - files: `lib/Controller/ConsultationController.php`, `appinfo/routes.php`
  - spec_ref: `specs/consultation-management/spec.md` §Document access scoping, §External body
  - acceptance: authenticated inbox route returns only consultations for user's groups; public token route rejects invalid/expired tokens with `403`; document attachment access verifies UUID against consultation's `relations` list (not parent case). No stack traces in error responses.

## Frontend Components

- [~] **TASK-CN-05** — Create `ConsultationCreateDialog.vue`, `ConsultationPanel.vue` (case-detail "Adviezen" tab with summary badge), `ConsultationDashboard.vue` (department inbox with `useListView`), and `ConsultationResponseForm.vue` (structured response with conditional `voorwaarden` editor using `CnTabbedFormDialog`). — deferred to downstream cycle / fleet-wide adoption (handoff)
  - files: `src/views/consultations/ConsultationCreateDialog.vue`, `src/views/consultations/ConsultationPanel.vue`, `src/views/consultations/ConsultationDashboard.vue`, `src/views/consultations/ConsultationResponseForm.vue`
  - spec_ref: `specs/consultation-management/spec.md` §Department inbox, §Structured response, §Case panel
  - acceptance: "Adviezen" tab shows "X/Y adviezen ontvangen" badge; dashboard overdue items sorted to top with red highlight; `voorwaarden` editor appears only when `positief_met_voorwaarden` is selected; all strings via `t(appName, …)`.

- [~] **TASK-CN-06** — Create `ExternalConsultationResponsePage.vue` for token-based external responses; register route outside the authenticated app shell in `appinfo/routes.php` and the Vue router. — deferred to downstream cycle / fleet-wide adoption (handoff)
  - files: `src/views/consultations/ExternalConsultationResponsePage.vue`, `src/router/router.js`, `appinfo/routes.php`
  - spec_ref: `specs/consultation-management/spec.md` §External advisory body
  - acceptance: page loads without Nextcloud login; valid token renders the response form; invalid/expired token renders a friendly error (no stack trace); WCAG AA touch targets ≥44×44 px.

## Admin Configuration UI

- [~] **TASK-CN-07** — Add caseType admin UI section to configure mandatory/optional consultation types per zaaktype: default advisory body, default deadline offset (ISO 8601 duration), and sequential dependencies between consultation types. — deferred to downstream cycle / fleet-wide adoption (handoff)
  - files: `src/views/settings/ConsultationTypeConfig.vue`, `lib/Controller/SettingsController.php`
  - spec_ref: `specs/consultation-management/spec.md` §Configurable consultation types
  - acceptance: admin can toggle `mandatory` flag; default deadline is stored as ISO 8601 duration (e.g. `P28D`); dependency graph rendered as a DAG; saving with a cycle shows validation error before API call.

## Activity Timeline Integration

- [~] **TASK-CN-08** — Wire `ActivityTimeline.vue` integration so consultation lifecycle events (create, acknowledge, response submitted, overdue warning) surface on the parent case via `\OCP\EventDispatcher\IEventDispatcher`. — deferred to downstream cycle / fleet-wide adoption (handoff)
  - files: `lib/Service/ConsultationNotificationService.php`, `src/views/cases/components/ActivityTimeline.vue`
  - spec_ref: `specs/consultation-management/spec.md` §Activity timeline
  - acceptance: all 6 lifecycle events listed in spec appear chronologically; overdue event is visually distinct (red/amber); events include actor name and consultation number as clickable link.

## n8n Workflows

- [~] **TASK-CN-09** — Add three n8n workflows: (1) daily deadline-monitor (T-5 warning + overdue escalation); (2) email-fanout for external advisory bodies on consultation creation; (3) bottleneck-detection alert when a body's overdue rate exceeds 20%. Document webhook contracts in `docs/n8n-consultation-workflows.md`. — deferred to downstream cycle / fleet-wide adoption (handoff)
  - files: `n8n/consultation-deadline-monitor.json`, `n8n/consultation-email-fanout.json`, `n8n/consultation-bottleneck-detection.json`, `docs/n8n-consultation-workflows.md`
  - spec_ref: `specs/consultation-management/spec.md` §Deadline warning, §Overdue escalation, §Bottleneck detection
  - acceptance: deadline-monitor workflow triggers once daily; email includes secure response link; bottleneck detection reads last-30-days overdue rate per body and sends coordinator notification when >20%.

## Internationalisation

- [~] **TASK-CN-10** — Add Dutch and English i18n strings for all consultation UI labels, notification templates, and status labels. — deferred to downstream cycle / fleet-wide adoption (handoff)
  - files: `l10n/nl.json`, `l10n/en.json`
  - spec_ref: ADR-007 i18n
  - acceptance: zero hardcoded Dutch or English strings remain in `.vue` files for consultation components; `t(appName, 'key')` used throughout; `npm run l10n:extract` passes without new untranslated keys.

## Deduplication Check

- [~] **TASK-CN-11** — Deduplication verification: confirm no overlap with existing OpenRegister services (`ObjectService`, `FileService`, `NotificationService`, `AuthorizationService`, `relationsPlugin`) and no duplication of existing `adviesAanvraag` schema. Document findings in `design.md` Reuse Analysis. — deferred to downstream cycle / fleet-wide adoption (handoff)
  - files: `openspec/changes/consultation-management/design.md`
  - spec_ref: ADR-012
  - acceptance: Reuse Analysis table completed; no new custom CRUD endpoints that duplicate `ObjectService`; no new audit-log table; no custom RBAC middleware.

## Seed Data Verification

- [~] **TASK-CN-12** — Verify seed data idempotency: run `importFromApp()` twice on a clean install and confirm no duplicate objects are created for `consultation`, `adviceResponse`, and `advisoryBody` slugs. — deferred to downstream cycle / fleet-wide adoption (handoff)
  - files: `lib/Settings/procest_register.json`
  - spec_ref: ADR-001 seed data requirements
  - acceptance: second import produces zero new objects; all slug lookups match by `ObjectService::searchObjects` with `_rbac: false` and `_multitenancy: false`.
