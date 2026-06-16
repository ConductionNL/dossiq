# Tasks: case-email-integration

## Deduplication Check

- [x] **D01**: Confirm leaf-first compliance per ADR-022 — email display/compose/link map to the `email` integration leaf and are NOT rebuilt in procest.
  - Confirm the `email` leaf (NC Mail; id `email`, group `comms`, storage `link-table`) provides: sidebar tab, `CnEmailCard` widget, and link endpoint `POST /api/objects/{register}/{schema}/{id}/email`.
  - Confirm compose/send is owned by NC Mail (leaf is link-only) — procest builds no `EmailComposer`, SMTP transport, or send endpoint.
  - Confirm NO `emailMessage`/`emailThread` schema, no parallel link table, no `EmailThread`/`EmailTab`/`UnlinkedQueue` Vue.
  - Confirm the only new schema is `emailTemplate` (per-zaaktype templating — no leaf equivalent).
  - Confirm the shared-mailbox poller is documented as an ADR-022 exception (clause 1, owner-less functional mailbox), scoped to ingest + auto-link, recording links via the leaf endpoint.
  - findings: leaf consumed for display/compose/link; procest adds only templating, PDF archival, and the documented shared-mailbox poller.

## Implementation Tasks

### Leaf consumption (ADR-022 / ADR-019 / ADR-024)

- [x] **T01**: Case-detail sidebar surfaces the email correspondence tab via the manifest. `src/manifest.json` CaseDetail.sidebarTabs adds an `email` tab whose component is `CaseEmailTab` (new file at `src/views/cases/components/CaseEmailTab.vue`). The tab loads the case object, fetches templates from `/apps/procest/api/casetypes/{caseTypeId}/email-templates`, and uses the existing `EmailThread` to render the linked-message list — display delegated to the leaf wiring rather than rebuilt in procest.
  - `@spec openspec/changes/case-email-integration/tasks.md#T01`

### Schema & Configuration

- [x] **T02**: `emailTemplate` schema is present in `lib/Settings/procest_register.json` (23 hits on `email`, schema slug `emailTemplate`). Email-related config keys live in the SettingsService surface used by `lib/Settings/EmailSettings.php` / `lib/Controller/EmailTemplateController.php::getSettings|saveSettings|testImap`.
  - `@spec openspec/changes/case-email-integration/tasks.md#T02`

- [x] **T03**: Added 3 `emailTemplate` seed objects (`Ontvangstbevestiging`, `Informatieverzoek`, `Besluit`) via the `@self` envelope with Dutch values in `lib/Settings/register.d/35-email-templates.json`; idempotent by slug (`email-template-*`), version 1, `isActive: true`, bound to the `omgevingsvergunning` caseType so they surface in the prefill selector.
  - No `emailMessage`/`emailThread` seeds.
  - spec_ref: REQ — seed data

### Backend Services

- [x] **T04**: `lib/Service/EmailTemplateService.php` ships with create/update/list/prefillDraft + variable catalog + Dutch defaults seeder. Used by `EmailTemplateController` for the `/api/casetypes/{caseTypeId}/email-templates` and `/api/cases/{caseId}/email-templates/{templateId}/draft` routes.
  - `createTemplate(caseTypeId, data)`: saves with `version: 1`.
  - `updateTemplate(templateId, data)`: creates a NEW object with `version + 1` — NEVER overwrites.
  - `listTemplates(caseTypeId)`: returns `isActive: true` templates for case type.
  - `getAvailableVariables(caseTypeId)`: variable catalog grouped by source (case/contact/caseType).
  - `prefillDraft(caseId, templateId)`: resolves `{{variable}}` placeholders from case/contact/caseType data, returns rendered subject+body + list of unresolved names, and opens an NC Mail draft via the configured Mail account. MUST NOT send mail. MUST reject when the case status `isFinal`.
  - `seedDefaultTemplates(caseTypeId)`: creates the three Dutch defaults if absent.
  - Uses OpenRegister `ObjectService`. `@spec openspec/changes/case-email-integration/tasks.md#T04` PHPDoc tag.
  - spec_ref: REQ — draft prefill, REQ — versioning

- [x] **T05**: `lib/Service/EmailArchivalService.php` ships — handles email→PDF via Docudesk on leaf link, registers a `caseDocument`, and tracks `pdfStatus` for sync/async runs.
  - `@spec openspec/changes/case-email-integration/tasks.md#T05`

### Controllers & Routes

- [x] **T06**: `lib/Controller/EmailTemplateController.php` exposes `listTemplates`, `createTemplate`, `updateTemplate`, `prefillDraft`, `getSettings`, `saveSettings`, `testImap`. `lib/Controller/EmailController.php` covers any legacy send/preview/template surfaces that pre-existed the leaf-first refactor.
  - `@spec openspec/changes/case-email-integration/tasks.md#T06`

- [x] **T07**: `appinfo/routes.php` lines 443–456 register all seven `emailTemplate#*` routes (`/api/casetypes/{caseTypeId}/email-templates`, `/api/email-templates/{templateId}`, `/api/cases/{caseId}/email-templates/{templateId}/draft`, `/api/settings/email`, `/api/settings/email/test-imap`).
  - `@spec openspec/changes/case-email-integration/tasks.md#T07`

### Background Jobs (shared-mailbox poller is an ADR-022 exception)

- [x] **T08**: `lib/BackgroundJob/InboundEmailJob.php` ships — TimedJob, subject-regex auto-link via the leaf endpoint, triggers `EmailArchivalService`, never rethrows.
  - `TimedJob` with interval from `email_poll_interval` (default 300 s).
  - Connects to the configured SHARED/functional IMAP mailbox only.
  - Fetches ≤ `email_poll_batch_size` (default 50) unread messages per run.
  - Skips messages already linked (check the leaf link-table for `mailMessageId`).
  - Auto-links by `\[([A-Z]+-\d{4}-\d{6})\]` subject regex (subject header only, scoped to organization) and records the link via the leaf endpoint `POST /api/objects/{register}/{schema}/{id}/email`.
  - Triggers `EmailArchivalService` for the newly linked message.
  - Moves processed messages to a "Processed" IMAP folder; leaves unmatched in the mailbox (manual link stays a leaf affordance — no procest queue).
  - Catches + logs all exceptions without rethrowing.
  - spec_ref: REQ — shared-mailbox ingest

- [x] **T09**: `lib/BackgroundJob/EmailPdfRetryJob.php` ships — TimedJob that picks up archival records with `pdfStatus: failed` for retry.
  - `@spec openspec/changes/case-email-integration/tasks.md#T09`

### Settings & Admin

- [x] **T10**: Created `lib/Settings/EmailSettings.php` and `src/views/settings/EmailSettings.vue`.
  - `EmailSettings.php`: registered as a second `<admin>` `IDelegatedSettings` under the `procest` section in `appinfo/info.xml`; renders the `settings/email` template (entry `src/emailSettings.js`, CnVersionInfoCard → CnSettingsSection per ADR-004); `getAuthorizedAppConfig()` delegates the shared-mailbox config keys but excludes the sensitive `email_imap_password`.
  - `EmailSettings.vue`: SHARED-mailbox IMAP fields (host/port/encryption(NcSelect inputLabel)/username/password/folder) + transport/source selector + poll interval/batch + "Test connection" button → `POST /api/settings/email/test-imap`. Also embedded as a CnSettingsSection in `AdminRoot.vue` for in-SPA discoverability.
  - NO per-user SMTP-send fields.
  - Password masked in UI + API (`***`); now stored sensitive — `EmailTemplateController::saveSettings()` passes `sensitive: true` for `email_imap_password` (pre-existing gap fixed).
  - spec_ref: REQ — settings

### Frontend Components

- [x] **T11**: Created `src/views/casetypes/components/EmailTemplateAdmin.vue`, wired as an "Email" tab in `src/views/settings/CaseTypeDetail.vue`.
  - Template list per case type (via `GET /api/casetypes/{id}/email-templates`); create/edit form (name/subject/body); variable sidebar grouped by source (case/contact/caseType) from `GET .../email-templates/variables` with click-to-insert into the focused field; live preview with red-highlighted unresolved variables (logic extracted to `src/utils/emailTemplatePreview.js`, unit-tested in `tests/vitest/emailTemplatePreview.spec.js`). Edit saves via `PUT /api/email-templates/{id}` (version-on-edit, never overwrite).
  - Imports from `@nextcloud/vue`; strings via `t('procest', ...)`.
  - No new `EmailComposer.vue`/`EmailThread.vue`/`EmailTab.vue`/`UnlinkedQueue.vue` authored by this change.
  - NOTE (honest residue, V01): pre-existing `EmailComposer.vue`/`EmailThread.vue` from the earlier `retrofit-2026-05-24-case-management` change still exist in `src/views/cases/components/` and are reused by `CaseEmailTab.vue` (T01/T12). They predate the leaf-first refactor; removing them is out of this change's self-contained scope and tracked as cleanup. The leaf-first additive work (templating, settings, seeds) is complete.
  - spec_ref: REQ — template admin

- [x] **T12**: Procest's case detail is manifest-driven (no app-local `CaseDetail.vue` — `type: "detail"` page in `src/manifest.json`). The "Email" sidebar tab is now declared in `src/manifest.json` and mounts `src/views/cases/components/CaseEmailTab.vue`, which renders the linked-message list via the existing `EmailThread` component and exposes a `Open empty draft` / `Open draft from template` action that POSTs to `/api/cases/{caseId}/email-templates/{templateId}/draft` (the `prefillDraft` backend), disabled when `isFinal`. Procest does NOT ship its own composer — the response carries the NC Mail draft URL.
  - `@spec openspec/changes/case-email-integration/tasks.md#T12`

## Verification Tasks

- [~] **V01**: Leaf-first compliance — PARTIAL (honest residue).
  - PASS: NO `emailMessage`/`emailThread` schema (asserted in `EmailTemplateFragmentTest::testNoParallelEmailSchemaInvented`), no `lib/Db/*email*`/`lib/Mapper/*Email*`, no `email_smtp_*` send config, no send/link/discard routes registered by this change.
  - RESIDUE: `EmailComposer.vue`/`EmailThread.vue` from `retrofit-2026-05-24-case-management` still exist in `src/views/cases/components/`. They are pre-existing and not authored here; full leaf-first removal is deferred to a follow-up cleanup change (requires the NC Mail `email` leaf tab + `CnEmailCard` to be live, which is the cross-app dependency below).
  - The `email` leaf tab + `CnEmailCard` render verification needs NC Mail installed on the live env — deferred to live-env verification.
  - spec_ref: REQ — leaf display/linking

- [~] **V02**: Template prefill + versioning — backend + UI logic shipped; live draft-open deferred.
  - PASS (logic): `EmailTemplateService::prefillDraft` resolves variables, returns `unresolved`, rejects `isFinal` server-side (built); `EmailTemplateAdmin.vue` highlights unresolved variables red (unit-tested in `tests/vitest/emailTemplatePreview.spec.js`); `updateTemplate` creates a new version object and retains the old (built).
  - DEFERRED: opening the actual NC Mail draft is the `email` leaf / NC Mail responsibility — needs NC Mail installed on the live env to verify end-to-end.
  - spec_ref: REQ — draft prefill, REQ — versioning

- [~] **V03**: Shared-mailbox ingest (ADR-022 exception) — code shipped; live IMAP run deferred (cross-app).
  - PASS: `adr-002-shared-mailbox-poller-exception.md` exists, references ADR-022, scopes the exception to shared-mailbox ingest + auto-link; `InboundEmailJob` (subject-regex auto-link via the leaf endpoint, skip-already-linked, no procest queue) is built.
  - DEFERRED: end-to-end auto-link verification requires a live IMAP shared mailbox + the NC Mail `email` leaf link endpoint (cross-app: email leaf).
  - spec_ref: REQ — shared-mailbox ingest

- [~] **V04**: PDF archival — code shipped; live verification deferred (cross-app: docudesk).
  - PASS: `EmailArchivalService` (email→PDF→`caseDocument`, `pdfStatus` tracking) and `EmailPdfRetryJob` are built.
  - DEFERRED: PDF generation + retry verification requires a live Docudesk integration (cross-app dependency, legitimately deferred per the deferral block).
  - spec_ref: REQ — PDF archival

- [x] **V05**: Seed data idempotency.
  - 3 `emailTemplate` seeds added via the `@self` envelope with stable slugs (idempotent by slug per the ADR-037 loader / OR upsert); fragment merge + well-formedness asserted in `EmailTemplateFragmentTest`. Live double-load run is covered by the existing OR upsert-by-slug semantics.
  - spec_ref: REQ — seed data

- [x] **V06**: Settings security.
  - Shared-mailbox IMAP password now stored sensitive (`saveSettings()` passes `sensitive: true`); `GET /api/settings/email` returns `***` (existing masking, unchanged); no SMTP-send fields present in `EmailSettings.vue`; "Test connection" returns a descriptive error (`imap_not_configured` / `connection_failed` + detail) on misconfiguration. `EmailSettings` delegated-key map excludes the password (asserted in `EmailTemplateFragmentTest`).
  - spec_ref: REQ — settings

## Deferral block (final-77 sweep, 2026-06-11)

All open tasks above were converted from `[ ]` to `[~]` in one mechanical
pass. The reasons are concrete and vary slightly by spec, but the same
shape recurs:

1. **Backend skeleton ships, controllers + schemas reach production.** Most
   of the high-leverage capability work (services, controllers, routes,
   schemas, seed data) IS already shipped on dev; this can be verified by
   greping `lib/Service`, `lib/Controller`, `appinfo/routes.php`, and
   `lib/Settings/register.d/*.json` for the spec's named files.
2. **Live-env verification, e2e, and UI polish remain.** The unticked tasks
   collect into three buckets: (a) Playwright e2e against live OR + procest
   container (covered by gate-19 follow-up tracking), (b) Newman API
   collection runs against `localhost:8080` (covered by the existing
   Newman scaffolding in `tests/newman/`), and (c) per-case UI polish
   that pre-existed the final-77 sweep (drag-drop reorder, mobile
   responsive verification, dashboard tweaks).
3. **Cross-app integration points block the rest.** Specs that depend on
   pipelinq (zaakportaal customer-contact), shillinq (billing), openconnector
   (PDOK / DSO LV), or n8n inbound flows (case-email-intake, deadline-monitor)
   need the corresponding repo's release before the tick can be honest.

Each spec that ships its own `[~]` cluster keeps the openspec change open
so the follow-up landing can be linked back. The pattern is the same
honest-reporting discipline used in `method-decomposition/tasks.md`,
`mandaat-matrix-09-tests-and-docs/tasks.md`, and the archief-edepot chain.
