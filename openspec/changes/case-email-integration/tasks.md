# Tasks: case-email-integration

## Deduplication Check

- [~] **D01**: Confirm leaf-first compliance per ADR-022 — email display/compose/link map to the `email` integration leaf and are NOT rebuilt in procest.
  - Confirm the `email` leaf (NC Mail; id `email`, group `comms`, storage `link-table`) provides: sidebar tab, `CnEmailCard` widget, and link endpoint `POST /api/objects/{register}/{schema}/{id}/email`.
  - Confirm compose/send is owned by NC Mail (leaf is link-only) — procest builds no `EmailComposer`, SMTP transport, or send endpoint.
  - Confirm NO `emailMessage`/`emailThread` schema, no parallel link table, no `EmailThread`/`EmailTab`/`UnlinkedQueue` Vue.
  - Confirm the only new schema is `emailTemplate` (per-zaaktype templating — no leaf equivalent).
  - Confirm the shared-mailbox poller is documented as an ADR-022 exception (clause 1, owner-less functional mailbox), scoped to ingest + auto-link, recording links via the leaf endpoint.
  - findings: leaf consumed for display/compose/link; procest adds only templating, PDF archival, and the documented shared-mailbox poller.

## Implementation Tasks

### Leaf consumption (ADR-022 / ADR-019 / ADR-024)

- [~] **T01**: Register the `case` schema as a host surface for the `email` leaf so its sidebar tab + `CnEmailCard` widget render on the case detail page.
  - Add the manifest entry (ADR-024) / integration-registry wiring (ADR-019) that surfaces provider id `email` on `case` objects.
  - Where a `case` property points at a primary correspondence message, set `referenceType: 'email'` in `procest_register.json` so `CnEmailCard` auto-renders inline (ADR per leaf spec).
  - Verify the tab + widget appear only when NC Mail is installed (leaf hides when `mail` app missing).
  - spec_ref: REQ — leaf display/linking

### Schema & Configuration

- [~] **T02**: Add ONLY the `emailTemplate` schema to `lib/Settings/procest_register.json` (Schema.org `schema:DigitalDocument`), fields per `design.md`.
  - Do NOT add `emailMessage` or `emailThread` schemas.
  - Add config keys to `SettingsService.php` `CONFIG_KEYS` / `SLUG_TO_CONFIG_KEY`: `email_template_schema`, plus shared-mailbox keys `email_imap_host`, `email_imap_port`, `email_imap_encryption`, `email_imap_username`, `email_imap_password`, `email_imap_folder`, `email_transport`, `email_poll_interval`, `email_poll_batch_size`, `email_max_attachment_size`.
  - Do NOT add `email_smtp_*` send keys — NC Mail owns send.
  - spec_ref: REQ — emailTemplate schema

- [~] **T03**: Add 3 `emailTemplate` seed objects (`Ontvangstbevestiging`, `Informatieverzoek`, `Besluit`) via `@self` envelope with Dutch values; idempotent by slug.
  - No `emailMessage`/`emailThread` seeds.
  - spec_ref: REQ — seed data

### Backend Services

- [~] **T04**: Create `lib/Service/EmailTemplateService.php`.
  - `createTemplate(caseTypeId, data)`: saves with `version: 1`.
  - `updateTemplate(templateId, data)`: creates a NEW object with `version + 1` — NEVER overwrites.
  - `listTemplates(caseTypeId)`: returns `isActive: true` templates for case type.
  - `getAvailableVariables(caseTypeId)`: variable catalog grouped by source (case/contact/caseType).
  - `prefillDraft(caseId, templateId)`: resolves `{{variable}}` placeholders from case/contact/caseType data, returns rendered subject+body + list of unresolved names, and opens an NC Mail draft via the configured Mail account. MUST NOT send mail. MUST reject when the case status `isFinal`.
  - `seedDefaultTemplates(caseTypeId)`: creates the three Dutch defaults if absent.
  - Uses OpenRegister `ObjectService`. `@spec openspec/changes/case-email-integration/tasks.md#T04` PHPDoc tag.
  - spec_ref: REQ — draft prefill, REQ — versioning

- [~] **T05**: Create `lib/Service/EmailArchivalService.php`.
  - On email-linked (poller or manual leaf link), read the linked message metadata via NC Mail, convert to PDF via Docudesk, register the PDF as a `caseDocument` linked to the case (ZGW informatieobject).
  - Track `pdfStatus` (`pending`/`completed`/`failed`); sync for ≤ 5 MB, async otherwise.
  - Map an `email_linked` event into the case audit trail (OR audit on the `case` object — no app-local audit table).
  - spec_ref: REQ — PDF archival

### Controllers & Routes

- [~] **T06**: Create `lib/Controller/EmailTemplateController.php`.
  - `@NoAdminRequired` on all methods; thin (<10 lines per ADR-003); delegate to services.
  - Methods: `listTemplates`, `createTemplate`, `updateTemplate`, `prefillDraft`, `getSettings`, `saveSettings`, `testImap`.
  - NO `sendEmail`/`listEmails`/`linkEmail`/`discardEmail`/`testSmtp` — those are the leaf's / NC Mail's.
  - `saveSettings` stores the shared-mailbox IMAP password as a sensitive `IAppConfig` key; `getSettings` masks with `***`.
  - spec_ref: REQ — controller, REQ — settings

- [~] **T07**: Add routes to `appinfo/routes.php` BEFORE the SPA catch-all.
  - `GET  /api/casetypes/{caseTypeId}/email-templates`
  - `POST /api/casetypes/{caseTypeId}/email-templates`
  - `PUT  /api/email-templates/{templateId}`
  - `POST /api/cases/{caseId}/email-templates/{templateId}/draft`
  - `GET  /api/settings/email`
  - `PUT  /api/settings/email`
  - `POST /api/settings/email/test-imap`
  - Do NOT add email send/list/link/discard/test-smtp routes.
  - spec_ref: REQ — controller

### Background Jobs (shared-mailbox poller is an ADR-022 exception)

- [~] **T08**: Create `lib/BackgroundJob/InboundEmailJob.php` (ADR-022 exception — see `openspec/architecture/adr-002-shared-mailbox-poller-exception.md`).
  - `TimedJob` with interval from `email_poll_interval` (default 300 s).
  - Connects to the configured SHARED/functional IMAP mailbox only.
  - Fetches ≤ `email_poll_batch_size` (default 50) unread messages per run.
  - Skips messages already linked (check the leaf link-table for `mailMessageId`).
  - Auto-links by `\[([A-Z]+-\d{4}-\d{6})\]` subject regex (subject header only, scoped to organization) and records the link via the leaf endpoint `POST /api/objects/{register}/{schema}/{id}/email`.
  - Triggers `EmailArchivalService` for the newly linked message.
  - Moves processed messages to a "Processed" IMAP folder; leaves unmatched in the mailbox (manual link stays a leaf affordance — no procest queue).
  - Catches + logs all exceptions without rethrowing.
  - spec_ref: REQ — shared-mailbox ingest

- [~] **T09**: Create `lib/BackgroundJob/EmailPdfRetryJob.php`.
  - `TimedJob` every 15 min; retries archival records with `pdfStatus: failed`.
  - Exponential backoff (15 min, 1 h, 4 h); after 3 failures leaves `failed` for operator investigation.
  - Register both jobs via `IJobList` in `Application::register()` or `appinfo/info.xml`.
  - spec_ref: REQ — PDF archival

### Settings & Admin

- [~] **T10**: Create `lib/Settings/EmailSettings.php` and `src/views/settings/EmailSettings.vue`.
  - `EmailSettings.php`: registers the admin settings section.
  - `EmailSettings.vue`: SHARED-mailbox IMAP fields (host/port/encryption/username/password/folder) + transport/source selector (which NC Mail account / functional mailbox) + "Test connection" button → `POST /api/settings/email/test-imap`.
  - NO per-user SMTP-send fields.
  - Layout: `CnVersionInfoCard` then `CnSettingsSection` (ADR-004). Password masked in UI + API (`***`); stored sensitive.
  - spec_ref: REQ — settings

### Frontend Components

- [~] **T11**: Create `src/views/casetypes/components/EmailTemplateAdmin.vue`.
  - Template list per case type; create/edit form (subject/body); variable sidebar grouped by source (case/contact/caseType) with click-to-insert; live preview with red-highlighted unresolved variables.
  - Import from `@conduction/nextcloud-vue`; strings via `t(appName, ...)`.
  - Do NOT create `EmailComposer.vue`, `EmailThread.vue`, `EmailTab.vue`, or `UnlinkedQueue.vue` — display/compose/link come from the leaf + NC Mail.
  - spec_ref: REQ — template admin

- [~] **T12**: Update `src/views/cases/CaseDetail.vue`.
  - Ensure the case detail page mounts the `email` leaf tab + `CnEmailCard` widget (via the manifest/registry wiring from T01).
  - Add a "Verstuur email" header action that opens an NC Mail draft prefilled from a template (calls `prefillDraft`), disabled when `isFinal`. It MUST NOT open a procest composer.
  - spec_ref: REQ — leaf display, REQ — draft prefill

## Verification Tasks

- [~] **V01**: Leaf-first compliance.
  - Codebase contains NO `emailMessage`/`emailThread` schema, no `lib/Db/*email*`/`lib/Mapper/*Email*`, no `EmailComposer`/`EmailThread`/`EmailTab`/`UnlinkedQueue` Vue, no `email_smtp_*` send config, no send/link/discard routes.
  - The `email` leaf tab + `CnEmailCard` render on the case detail page when NC Mail is installed.
  - spec_ref: REQ — leaf display/linking

- [~] **V02**: Template prefill + versioning.
  - `prefillDraft` resolves variables and opens an NC Mail draft; unresolved variables are returned and highlighted; no draft created with raw tokens; rejected when `isFinal`.
  - Template edit creates a new version object; old version retained.
  - spec_ref: REQ — draft prefill, REQ — versioning

- [~] **V03**: Shared-mailbox ingest (ADR-022 exception).
  - `adr-002-shared-mailbox-poller-exception.md` exists, references ADR-022, scopes the exception to shared-mailbox ingest + auto-link.
  - Subject-tagged `[ZAAK-2026-000142]` shared-mailbox email auto-links to the matching case via the leaf endpoint; an already-linked `mailMessageId` is skipped; unmatched mail leaves no procest queue.
  - spec_ref: REQ — shared-mailbox ingest

- [~] **V04**: PDF archival.
  - Linking an email produces a `caseDocument` PDF via Docudesk; Docudesk failure does not block the link and sets `pdfStatus: failed`; `EmailPdfRetryJob` re-attempts up to 3×.
  - spec_ref: REQ — PDF archival

- [~] **V05**: Seed data idempotency.
  - Run `openregister:load-register` twice; no duplicate `emailTemplate` objects; seeds appear in the prefill selector for the matching case type.
  - spec_ref: REQ — seed data

- [~] **V06**: Settings security.
  - Shared-mailbox IMAP password stored sensitive; `GET /api/settings/email` returns `***`; no SMTP-send fields present; "Test connection" returns a descriptive error on misconfiguration.
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
