# Tasks: case-email-integration

## Deduplication Check

- [ ] **D01**: Verify no existing procest service or OpenRegister platform capability duplicates any logic introduced in this change.
  - Scan `openspec/specs/` for any existing email-related spec (none found — no prior email integration exists in procest).
  - Confirm `ObjectService` (OpenRegister) is reused for CRUD on `emailTemplate`, `emailMessage`, `emailThread` — no custom Mapper/Entity classes.
  - Confirm Docudesk integration is the existing PDF generation path — no custom renderer is introduced.
  - Confirm `FileService` + `caseDocument` schema handle PDF file storage — no custom file upload controller.
  - Confirm `ActivityService` (OpenRegister, existing in procest) handles `email_sent`/`email_received` activity entries — no custom audit table.
  - Confirm `IAppConfig` + existing `SettingsService` pattern handles config persistence — no new DB table for email settings.
  - Confirm `IJobList` + `TimedJob` (existing Nextcloud pattern, already used in procest) handles background job scheduling.
  - findings: All capabilities reused from platform. New code limited to SMTP/IMAP transport, RFC 2822 parsing, template variable resolution, and Vue composer/thread UI — no platform equivalents exist.

## Implementation Tasks

### Schema & Configuration

- [ ] **T01**: Add `emailTemplate`, `emailMessage`, `emailThread` schemas to `lib/Settings/procest_register.json`.
  - Fields per `design.md` data model tables; Schema.org annotations `schema:DigitalDocument`, `schema:EmailMessage`, `schema:Conversation`.
  - Add config keys to `SettingsService.php` `CONFIG_KEYS` and `SLUG_TO_CONFIG_KEY`:
    `email_template_schema`, `email_message_schema`, `email_thread_schema`,
    `email_smtp_host`, `email_smtp_port`, `email_smtp_encryption`, `email_smtp_username`, `email_smtp_password`,
    `email_from_address`, `email_imap_host`, `email_imap_port`, `email_imap_folder`,
    `email_transport`, `email_poll_interval`, `email_poll_batch_size`, `email_max_attachment_size`.
  - spec_ref: REQ-CEI-001

- [ ] **T02**: Add seed data for all three schemas to `procest_register.json` using `@self` envelope with Dutch realistic values.
  - 3 `emailTemplate` objects: `Ontvangstbevestiging`, `Informatieverzoek`, `Besluit` (slugs from `design.md`).
  - 3 `emailThread` objects with Dutch case references (slugs from `design.md`).
  - 4 `emailMessage` objects with outbound/inbound mix (slugs from `design.md`).
  - Idempotency: existing objects matched by slug, not duplicated on re-import.
  - spec_ref: REQ-CEI-011

### Backend Services

- [ ] **T03**: Create `lib/Service/CaseEmailService.php`.
  - `sendEmail(caseId, templateId, subject, body, recipients, cc, bcc, attachmentIds)`: resolve template variables → generate RFC 2822 `Message-ID` → dispatch via transport → store `emailMessage` → find/create `emailThread` → append `email_sent` activity → trigger PDF conversion.
  - `processInboundEmail(rawMessage)`: parse headers → auto-link by `\[([A-Z]+-\d{4}-\d{6})\]` subject regex (subject header only, scoped to organization) → auto-link by `In-Reply-To` → store `emailMessage` + update/create `emailThread` → queue unlinked with `case: null`.
  - `resolveTemplateVariables(template, case)`: returns rendered subject + body; lists unresolved variable names.
  - `linkUnlinkedEmail(emailId, caseId)`: updates `emailMessage.case` reference.
  - `discardUnlinkedEmail(emailId, reason)`: marks message as discarded.
  - Uses OpenRegister `ObjectService`; Docudesk for PDF; `@spec openspec/changes/case-email-integration/tasks.md#T03` PHPDoc tag.
  - spec_ref: REQ-CEI-002, REQ-CEI-003, REQ-CEI-004

- [ ] **T04**: Create `lib/Service/EmailTemplateService.php`.
  - `createTemplate(caseTypeId, data)`: saves with `version: 1`.
  - `updateTemplate(templateId, data)`: creates a NEW object with `version + 1` — NEVER overwrites.
  - `listTemplates(caseTypeId)`: returns `isActive: true` templates for case type.
  - `getAvailableVariables(caseTypeId)`: returns variable catalog grouped by source (case/contact/caseType).
  - `seedDefaultTemplates(caseTypeId)`: creates `Ontvangstbevestiging`, `Informatieverzoek`, `Besluit` if absent.
  - spec_ref: REQ-CEI-006

### Controllers & Routes

- [ ] **T05**: Create `lib/Controller/CaseEmailController.php`.
  - `@NoAdminRequired` on all methods. All methods thin (<10 lines per ADR-003); delegate to services.
  - Methods: `sendEmail`, `listEmails`, `listUnlinked`, `linkEmail`, `discardEmail`, `listTemplates`, `createTemplate`, `updateTemplate`, `getSettings`, `saveSettings`, `testSmtp`.
  - Returns `JSONResponse`; `saveSettings` stores SMTP/IMAP passwords as sensitive `IAppConfig` keys; `getSettings` masks passwords with `***`.
  - spec_ref: REQ-CEI-007, REQ-CEI-010

- [ ] **T06**: Add routes to `appinfo/routes.php` BEFORE the SPA catch-all.
  - `POST /api/cases/{caseId}/emails`
  - `GET  /api/cases/{caseId}/emails`
  - `GET  /api/emails/unlinked`
  - `POST /api/emails/unlinked/{id}/link`
  - `POST /api/emails/unlinked/{id}/discard`
  - `GET  /api/casetypes/{caseTypeId}/email-templates`
  - `POST /api/casetypes/{caseTypeId}/email-templates`
  - `PUT  /api/email-templates/{templateId}`
  - `GET  /api/settings/email`
  - `PUT  /api/settings/email`
  - `POST /api/settings/email/test-smtp`
  - spec_ref: REQ-CEI-007

### Background Jobs

- [ ] **T07**: Create `lib/BackgroundJob/InboundEmailJob.php`.
  - `TimedJob` with interval from `email_poll_interval` (default 300 s).
  - Connects to IMAP via `imap_open()` or Nextcloud Mail account API.
  - Fetches ≤ `email_poll_batch_size` (default 50) unread messages per run.
  - Skips messages whose `messageId` already exists (duplicate detection before any other processing).
  - Delegates to `CaseEmailService::processInboundEmail()` per message.
  - Moves processed messages to "Processed" IMAP folder; catches + logs all exceptions without rethrowing.
  - spec_ref: REQ-CEI-003

- [ ] **T08**: Create `lib/BackgroundJob/EmailPdfRetryJob.php`.
  - `TimedJob` running every 15 min.
  - Finds `emailMessage` objects with `pdfStatus: failed`; retries Docudesk conversion.
  - Exponential backoff: retry 1 after 15 min, retry 2 after 1 h, retry 3 after 4 h. After 3 failures, leaves `pdfStatus: failed` for operator investigation.
  - Register both jobs via `IJobList` in `Application::register()` or `appinfo/info.xml` background-jobs section.
  - spec_ref: REQ-CEI-005

### Settings & Admin

- [ ] **T09**: Create `lib/Settings/EmailSettings.php` and `src/views/settings/EmailSettings.vue`.
  - `EmailSettings.php`: registers admin settings section in Nextcloud.
  - `EmailSettings.vue`: SMTP fields (host/port/encryption/username/password/from-address), IMAP fields (host/port/encryption/username/password/folder), transport selector (Nextcloud Mail account picker or standalone), "Send test email" button.
  - Layout: `CnVersionInfoCard` first, then `CnSettingsSection` per group (per ADR-004 admin pattern).
  - Passwords masked in UI and in `GET /api/settings/email` response (`***`); stored as sensitive `IAppConfig` keys.
  - Register settings section in `appinfo/info.xml`.
  - spec_ref: REQ-CEI-010

### Frontend Components

- [ ] **T10**: Create `src/views/cases/components/EmailComposer.vue`.
  - Modal compose dialog; recipient pre-filled from case contact; CC/BCC; subject pre-filled with `[{identifier}]` prefix.
  - Rich-text body editor; template selector dropdown; attachment picker from case `caseDocument` objects.
  - Running attachment size display; validation against `email_max_attachment_size`.
  - Send confirmation step; entire dialog disabled when `isFinal`.
  - Import from `@conduction/nextcloud-vue` only; all strings via `t(appName, ...)`.
  - spec_ref: REQ-CEI-008

- [ ] **T11**: Create `src/views/cases/components/EmailThread.vue` and `src/views/cases/components/EmailTab.vue`.
  - `EmailThread.vue`: chronological render (oldest first); inbound left-aligned, outbound right; inline expand/collapse; PDF download link; Reply button opens `EmailComposer` pre-populated with `In-Reply-To`.
  - `EmailTab.vue`: groups messages by thread (most recent first); collapsible groups; message-count badge in tab header.
  - spec_ref: REQ-CEI-008

- [ ] **T12**: Create `src/views/casetypes/components/EmailTemplateAdmin.vue` and `src/views/emails/UnlinkedQueue.vue`.
  - `EmailTemplateAdmin.vue`: template list per case type; create/edit form with subject/body; variable sidebar grouped by source (case/contact/caseType) with click-to-insert; live preview with red-highlighted unresolved variables.
  - `UnlinkedQueue.vue`: lists `emailMessage` with `case: null`; per-row: sender/subject/timestamp/body preview; search-and-link UI; discard action uses `NcDialog` (not `window.confirm()`).
  - spec_ref: REQ-CEI-009

- [ ] **T13**: Update `src/views/cases/CaseDetail.vue`.
  - Add `EmailTab` to the sidebar tabs in `sidebarProps`.
  - Add "Verstuur email" header action that opens `EmailComposer` (disabled when `isFinal`).
  - Subscribe to email events to refresh `ActivityTimeline` after send.
  - spec_ref: REQ-CEI-008

## Verification Tasks

- [ ] **V01**: Schemas and routes load correctly.
  - `procest_register.json` valid JSON; `openregister:load-register` succeeds with no validation errors.
  - All 11 routes resolve under `/index.php/apps/procest/api/` before the SPA catch-all.
  - spec_ref: REQ-CEI-001, REQ-CEI-007

- [ ] **V02**: Outbound email end-to-end.
  - Send produces a stored `emailMessage` with `direction: outbound` and `sentAt` set.
  - PDF generated and `pdfStatus: completed`; `email_sent` appears in case activity timeline.
  - Docudesk failure: `pdfStatus: failed`; retry job re-attempts up to 3×.
  - spec_ref: REQ-CEI-002, REQ-CEI-005

- [ ] **V03**: Inbound email and threading.
  - Subject-tagged inbound `[ZAAK-2026-000142]` auto-links to the matching case.
  - `In-Reply-To` inbound appended to the existing thread; `messageCount` incremented.
  - Unrecognised mail surfaces in `GET /api/emails/unlinked`; manual link removes it from the queue.
  - spec_ref: REQ-CEI-003, REQ-CEI-004

- [ ] **V04**: Template versioning and variable resolution.
  - Template variables resolve from case data; unresolved variables highlighted red in live preview.
  - Template edit creates a new version object; previously sent `emailMessage` objects retain original `templateVersion`.
  - spec_ref: REQ-CEI-006

- [ ] **V05**: Seed data idempotency.
  - Run `openregister:load-register` twice; confirm no duplicate `emailTemplate`, `emailMessage`, or `emailThread` objects are created.
  - Seed templates appear in `EmailComposer` dropdown for the matching case type.
  - spec_ref: REQ-CEI-011

- [ ] **V06**: Admin settings security.
  - SMTP/IMAP passwords stored as sensitive `IAppConfig` keys; `GET /api/settings/email` returns `***` not plaintext.
  - "Send test email" returns a descriptive error on misconfiguration.
  - spec_ref: REQ-CEI-010
