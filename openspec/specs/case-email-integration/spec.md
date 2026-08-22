---
status: done
---

# case-email-integration Specification

**Status:** partial — all self-contained additive work built and unit-verified (emailTemplate schema + 3 Dutch seeds, EmailTemplateService/Controller/routes, InboundEmailJob + EmailPdfRetryJob, EmailArchivalService, EmailSettings admin surface, EmailTemplateAdmin editor). Residual: pre-existing EmailComposer/EmailThread Vue from `retrofit-2026-05-24-case-management` not yet removed (leaf-first cleanup deferred); live end-to-end verification of NC Mail draft-open, IMAP auto-link, and Docudesk PDF archival deferred pending those cross-app dependencies.

## Purpose
Link every relevant email to its case and surface it on the case detail page (consuming the OpenRegister `email` integration leaf for display/compose/link per ADR-022), archive linked mail as a PDF `caseDocument` for Archiefwet/ZGW compliance, and add the only genuinely case-specific extensions the leaf cannot provide: per-zaaktype email templating, a documented shared-mailbox ingest poller, and shared-mailbox admin settings.

## Requirements
### Requirement: Email display and linking on the case consume the `email` integration leaf

Email correspondence on a `case` MUST be displayed and linked through the OpenRegister `email` integration leaf (NC Mail; provider id `email`, group `comms`, storage `link-table`), per hydra ADR-022 (integrate, don't build), ADR-019 (integration registry), and ADR-024 (app manifest). Dossiq MUST NOT build a parallel email message store, compose dialog, thread view, or link table.

- The `case` schema MUST be registered as a host surface so the leaf's email sidebar tab and `CnEmailCard` widget appear on the case detail page.
- Linking an email to a case MUST use the leaf endpoint `POST /api/objects/{register}/{schema}/{id}/email` with `{mailAccountId, mailMessageId}`; unlink is the leaf's own action.
- Composing/sending an email MUST happen in NC Mail (the leaf is link-only). Dossiq MAY prefill an NC Mail draft from a template, but MUST NOT send mail itself.
- No `emailMessage` or `emailThread` schema, no `EmailComposer.vue`, `EmailThread.vue`, `EmailTab.vue`, or `UnlinkedQueue.vue` MAY be created.

@e2e exclude Leaf display/linking is owned by NC Mail's `email` integration leaf (cross-app); rendering of the leaf tab/widget and the link endpoint cannot be exercised by dossiq UI e2e without NC Mail installed. Reviewer no-parallel-storage scan is a static code check, not a UI surface.

#### Scenario: Linked emails appear via the leaf tab on the case

- **GIVEN** NC Mail is installed and the `email` leaf is registered on the `case` surface
- **WHEN** a case worker opens the case detail page
- **THEN** the leaf's email sidebar tab MUST list emails linked to that case (subject, sender, date) without any dossiq-authored email display component

#### Scenario: Reviewer confirms no parallel email storage or UI

- **GIVEN** the dossiq codebase after this change
- **WHEN** scanned for `emailMessage`/`emailThread` schemas, `lib/Db/*email*`, `lib/Mapper/*Email*`, or `EmailComposer`/`EmailThread`/`EmailTab`/`UnlinkedQueue` Vue files
- **THEN** no such files SHALL exist; email display, compose, and link flow through the `email` leaf and NC Mail

#### Scenario: Linking uses the leaf link endpoint

- **GIVEN** an email selected for linking to case `ZAAK-2026-000142`
- **WHEN** the link is recorded
- **THEN** it MUST be persisted via `POST /api/objects/{register}/{schema}/{id}/email`, NOT a dossiq-local table

---

### Requirement: The system SHALL provide per-zaaktype email templates as a leaf extension

`emailTemplate` (`schema:DigitalDocument`) MUST be declared in `lib/Settings/dossiq_register.json` as the ONLY new email schema. It is a dossiq extension because NC Mail has no per-zaaktype templating bound to case data. Templates prefill an NC Mail draft; they do NOT introduce a send path.

No custom PHP Entity, Mapper, or database table MAY be created for `emailTemplate`; storage flows through OpenRegister `ObjectService`.

**emailTemplate fields:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | Yes | Display name, e.g. `Ontvangstbevestiging` |
| `subject` | string | Yes | Subject pattern with `{{variable}}` placeholders |
| `body` | string (HTML) | Yes | Body with `{{variable}}` placeholders |
| `caseType` | string | Yes | OR reference to `caseType` |
| `variables` | array | No | Variable names present in subject + body |
| `version` | integer | No | Incremented on each edit (starts at 1) |
| `isActive` | boolean | No | Whether selectable (default: true) |

@e2e exclude Schema declaration + enumeration is an OpenRegister config/load-register concern; covered by PHPUnit (EmailTemplateFragmentTest) + OR schema validation, not a dossiq UI surface.

#### Scenario: Template schema loads without errors

- **GIVEN** dossiq is installed and `dossiq_register.json` contains the `emailTemplate` schema
- **WHEN** `openregister:load-register` is executed
- **THEN** the schema MUST be created without validation errors and be accessible via the OR object API

#### Scenario: No emailMessage or emailThread schema is declared

- **GIVEN** `dossiq_register.json` after this change
- **WHEN** its schemas are enumerated
- **THEN** `emailMessage` and `emailThread` MUST NOT be present; linked emails are held in the leaf link-table

---

### Requirement: The system SHALL prefill an NC Mail draft from a template — it SHALL NOT send mail itself

`EmailTemplateService` MUST resolve `{{variable}}` placeholders from case, contact, and caseType data and hand the rendered subject + body to NC Mail as a **draft** (via the configured Mail account). Dossiq MUST NOT operate an SMTP transport.

The method MUST return the list of unresolved variable names so the frontend can highlight them in red; a draft MUST NOT be created containing raw `{{...}}` tokens.

@e2e exclude `EmailTemplateService::prefillDraft` variable resolution, unresolved-name return, and isFinal-reject are backend service logic covered by PHPUnit + the Newman draft-prefill endpoint; opening the actual NC Mail draft is cross-app (NC Mail), not a dossiq UI surface.

#### Scenario: Template variables resolve before prefilling a draft

- **GIVEN** template body `Geachte {{contact.salutation}}, zaaknummer {{case.identifier}}`
- **WHEN** the draft-prefill flow runs for case `ZAAK-2026-000142`
- **THEN** the rendered body MUST contain the actual salutation and identifier — never raw `{{...}}` tokens
- **AND** the email MUST be opened as an NC Mail draft, NOT dispatched by dossiq

#### Scenario: Unresolved variables are returned, not sent blind

- **GIVEN** a template containing `{{case.nonExistentField}}`
- **WHEN** the draft-prefill flow processes it
- **THEN** the method MUST return the list of unresolved names so the frontend highlights them; no draft with raw placeholder tokens MUST be created

#### Scenario: Final-status case blocks the compose action

- **GIVEN** a case with `isFinal: true` on its current status
- **WHEN** a handler views the case detail page
- **THEN** the "Verstuur email" action MUST be disabled with an explanatory message; the draft-prefill endpoint MUST reject the call server-side as well

---

### Requirement: The system SHALL version email templates on edit — old versions are retained, not overwritten

`EmailTemplateService::updateTemplate(templateId, data)` MUST create a **new** `emailTemplate` OR object with `version` incremented. The previous version MUST remain. Overwriting the existing object is forbidden.

@e2e exclude Version-on-edit semantics and per-case-type default seeding are backend service logic covered by PHPUnit; verifying retained OR object versions is not a dossiq UI assertion.

#### Scenario: Template update creates new version, old version retained

- **GIVEN** template `Ontvangstbevestiging` at `version: 1`
- **WHEN** an admin updates the body via `updateTemplate()`
- **THEN** a new `emailTemplate` with `version: 2` MUST be created
- **AND** the `version: 1` object MUST still exist with unchanged content

#### Scenario: Default Dutch templates are seeded per case type

- **GIVEN** a newly created case type with no custom templates
- **WHEN** the admin views the templates tab
- **THEN** `Ontvangstbevestiging`, `Informatieverzoek`, and `Besluit` MUST be offered

---

### Requirement: The system SHALL ingest a shared functional mailbox and auto-link to cases — a documented ADR-022 exception

`lib/BackgroundJob/InboundEmailJob.php` MUST be a `TimedJob` (interval from `email_poll_interval`, default 300 s) that ingests a **shared/functional mailbox** (e.g. `zaken@gemeente.nl`) with no per-user NC Mail account owner. This is an explicit ADR-022 § Exceptions case, justified in `openspec/architecture/adr-002-shared-mailbox-poller-exception.md`, because the link-only `email` leaf inherits per-user Mail access and cannot ingest an owner-less mailbox unattended.

The job MUST be scoped strictly to ingest + auto-link, and MUST record every link **through the leaf link endpoint** — NOT a dossiq-local message store. Per run:

1. Connect to the configured shared IMAP mailbox
2. Fetch up to `email_poll_batch_size` (default 50) unread messages from the configured folder
3. Skip messages already linked (check the leaf link-table for the `mailMessageId`)
4. Auto-link by matching `\[([A-Z]+-\d{4}-\d{6})\]` in the **subject header only** against cases scoped to the current organization
5. Record the link via `POST /api/objects/{register}/{schema}/{id}/email`
6. Move processed messages to the "Processed" IMAP folder
7. Leave unmatched messages in the mailbox (manual linking remains a leaf affordance — no dossiq queue)
8. Catch all exceptions without rethrowing; log via `LoggerInterface`

@e2e exclude `InboundEmailJob` is a headless TimedJob ingesting a live IMAP mailbox and writing via the NC Mail leaf endpoint (cross-app); it has no dossiq UI surface. Auto-link/skip/no-queue behaviour and the ADR-022 exception doc are covered by PHPUnit + the static ADR check.

#### Scenario: Subject-tagged inbound email auto-links via the leaf

- **GIVEN** a shared-mailbox email with subject `[ZAAK-2026-000142] Vraag over mijn vergunning`
- **WHEN** `InboundEmailJob` runs and the regex matches case `ZAAK-2026-000142`
- **THEN** the email MUST be linked to that case via the leaf link endpoint, NOT stored in a dossiq `emailMessage` object

#### Scenario: Already-linked message is skipped

- **GIVEN** a `mailMessageId` already linked to a case in the leaf link-table
- **WHEN** `InboundEmailJob` encounters the same message during polling
- **THEN** it MUST NOT create a duplicate link; the job MUST continue processing remaining messages

#### Scenario: Unmatched email is left for the leaf's manual link affordance

- **GIVEN** a shared-mailbox email with no recognizable case tag
- **WHEN** `InboundEmailJob` processes it
- **THEN** dossiq MUST NOT create an app-local unlinked queue; the message remains linkable via the leaf tab's "Link existing email"

#### Scenario: Exception is documented per ADR-022

- **GIVEN** this requirement ships a server-side poller
- **WHEN** a reviewer checks the ADR-022 exception discipline
- **THEN** `openspec/architecture/adr-002-shared-mailbox-poller-exception.md` MUST exist, reference ADR-022, and scope the exception to shared-mailbox ingest + auto-link only

---

### Requirement: The system SHALL archive linked emails as PDF `caseDocument` via Docudesk

When an email is linked to a case (by the shared-mailbox poller or manually via the leaf), `EmailArchivalService` MUST convert it to PDF via the existing Docudesk integration and register the PDF as a `caseDocument` linked to the case, for Archiefwet / ZGW informatieobject compliance. The leaf does not archive; this is a dossiq extension that reads the linked message's metadata via NC Mail.

`pdfStatus` tracks state: `pending` → `completed` or `failed`. Conversion is synchronous for messages ≤ 5 MB; asynchronous for larger. `EmailPdfRetryJob` (every 15 min) retries `pdfStatus: failed` up to 3× with exponential backoff (15 min, 1 h, 4 h).

@e2e exclude PDF archival + retry run via `EmailArchivalService`/`EmailPdfRetryJob` against the Docudesk integration (cross-app, headless background jobs); no dossiq UI surface. Covered by PHPUnit and live cross-app verification.

#### Scenario: Docudesk failure does not block linking

- **GIVEN** Docudesk is temporarily unavailable
- **WHEN** an email is linked to a case
- **THEN** the leaf link MUST still be recorded
- **AND** the archival MUST be marked `pdfStatus: failed` and queued for retry

#### Scenario: Retry job re-attempts failed conversions

- **GIVEN** three archival records with `pdfStatus: failed`
- **WHEN** `EmailPdfRetryJob` runs and Docudesk is available
- **THEN** all three MUST be retried; successful conversions MUST set `pdfStatus: completed` and register a `caseDocument`

---

### Requirement: The system SHALL expose template and shared-mailbox-settings operations through a controller, before the SPA catch-all

`lib/Controller/EmailTemplateController.php` is an authenticated Nextcloud controller (`@NoAdminRequired` on all methods). It MUST expose ONLY template CRUD, draft-prefill, and shared-mailbox settings — and MUST NOT expose email send/list/link/unlink (those are the leaf's). Endpoints:

| Method | Path | Handler |
|--------|------|---------|
| `GET` | `/api/casetypes/{caseTypeId}/email-templates` | `listTemplates` |
| `POST` | `/api/casetypes/{caseTypeId}/email-templates` | `createTemplate` |
| `PUT` | `/api/email-templates/{templateId}` | `updateTemplate` |
| `POST` | `/api/cases/{caseId}/email-templates/{templateId}/draft` | `prefillDraft` |
| `GET` | `/api/settings/email` | `getSettings` |
| `PUT` | `/api/settings/email` | `saveSettings` |
| `POST` | `/api/settings/email/test-imap` | `testImap` |

All routes MUST be registered in `appinfo/routes.php` BEFORE the Vue SPA catch-all per ADR-003.

@e2e exclude Controller endpoint routing + the absence of send/link routes are API/route-registration concerns covered by Newman + the route-reachability gate, not a dossiq UI surface.

#### Scenario: API routes resolve before SPA catch-all

- **GIVEN** `GET /index.php/apps/dossiq/api/casetypes/{caseTypeId}/email-templates` is requested
- **WHEN** Nextcloud dispatches the request
- **THEN** it MUST be handled by `EmailTemplateController::listTemplates()`, not the Vue SPA fallback

#### Scenario: No bespoke email send/link endpoints are registered

- **GIVEN** `appinfo/routes.php` after this change
- **WHEN** its routes are enumerated
- **THEN** there MUST be no `POST /api/cases/{caseId}/emails`, `/api/emails/unlinked`, `.../link`, `.../discard`, or `.../test-smtp` routes; those are the leaf's responsibility

---

### Requirement: The system SHALL provide `EmailTemplateAdmin.vue` for per-case-type template CRUD

`EmailTemplateAdmin.vue` (in `CaseTypeDetail.vue`) MUST:

- List templates for the current case type
- Provide a create/edit form with subject/body fields and a variable sidebar grouped by source (case/contact/caseType) with click-to-insert
- Show a live preview with unresolved variables highlighted in red

It MUST import from `@conduction/nextcloud-vue` (ADR-004) and route all user-visible strings via `t(appName, 'text')`. No bespoke compose/thread/queue components are introduced.

#### Scenario: Unresolved variable highlighted in live preview

@e2e exclude The red unresolved-variable highlight logic is unit-tested in tests/vitest/emailTemplatePreview.spec.js; exercising it in the live editor requires a seeded caseType + template (data-dependent), so it is covered by the vitest unit suite rather than Playwright.

- **GIVEN** template body containing `{{case.nonExistentField}}`
- **WHEN** the admin views the live preview in `EmailTemplateAdmin.vue`
- **THEN** the placeholder MUST be rendered with a red background highlight and a warning listing unresolved names

#### Scenario: Composer is the leaf / NC Mail, not a dossiq component

- **GIVEN** a handler clicks "Verstuur email" on a case
- **WHEN** the compose flow opens
- **THEN** it MUST open an NC Mail draft (optionally prefilled from a template), NOT a dossiq-authored `EmailComposer.vue`

---

### Requirement: The system SHALL provide admin settings for the shared mailbox only

`lib/Settings/EmailSettings.php` registers a Nextcloud admin settings section. `src/views/settings/EmailSettings.vue` MUST render ONLY:

- **Shared-mailbox IMAP**: host, port, encryption, username, password (masked), folder (default: INBOX)
- **Transport / source selector**: which NC Mail account or functional mailbox is the case-correspondence source
- **"Test connection" button** calling `POST /api/settings/email/test-imap`

Per-user SMTP/IMAP is NOT configured here — NC Mail owns user accounts. The shared-mailbox password is stored via `IAppConfig` with `setSensitive(true)` and MUST NOT appear in API responses in plaintext (return `***`). Layout follows ADR-004: `CnVersionInfoCard` first, then `CnSettingsSection`.

#### Scenario: Saved shared-mailbox password not returned in plaintext

@e2e exclude Password masking is an API response contract (`GET /api/settings/email` returns `***`) covered by Newman + PHPUnit; sensitive storage is verified by EmailTemplateFragmentTest. Not assertable as a dossiq UI surface (the field is a password input).

- **GIVEN** an admin saves shared-mailbox IMAP credentials
- **WHEN** `GET /api/settings/email` is called
- **THEN** the response MUST contain `"imap_password": "***"`, not the actual password

#### Scenario: No per-user SMTP send configuration is exposed

- **GIVEN** the admin email settings page
- **WHEN** the admin views the form
- **THEN** there MUST be no SMTP-send credential fields; outbound mail is sent via NC Mail

---

### Requirement: The system SHALL include seed data for the `emailTemplate` schema in `dossiq_register.json`

Per ADR-001, `dossiq_register.json` MUST include realistic seed `emailTemplate` objects using the `@self` envelope (3 templates: `Ontvangstbevestiging`, `Informatieverzoek`, `Besluit` as defined in `design.md`). No `emailMessage`/`emailThread` seeds — linked emails live in the leaf link-table, populated at runtime. Seed loading MUST be idempotent — slug-matched objects are not duplicated.

@e2e exclude Seed idempotency is an OpenRegister load-register concern (slug upsert) covered by PHPUnit (EmailTemplateFragmentTest); the prefill-selector appearance is data-dependent on a live seeded caseType + NC Mail draft flow (cross-app), not assertable as a standalone dossiq UI e2e here.

#### Scenario: Seed templates load idempotently

- **GIVEN** `openregister:load-register` has already run once
- **WHEN** it runs again with `force: false`
- **THEN** no duplicate `emailTemplate` objects MUST be created; slug-matched objects are skipped

#### Scenario: Seed templates appear in the draft-prefill selector

- **GIVEN** the seed data is loaded and a case of the matching `caseType` is open
- **WHEN** a handler opens the template selector to prefill a draft
- **THEN** `Ontvangstbevestiging`, `Informatieverzoek`, and `Besluit` MUST appear

