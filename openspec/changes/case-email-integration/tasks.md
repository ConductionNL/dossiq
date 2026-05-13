# Tasks: case-email-integration

## Implementation Tasks

### Schema & Configuration

- [ ] **T01**: Add `emailTemplate`, `emailMessage`, `emailThread` schemas to `lib/Settings/procest_register.json` with all fields from design (name/subject/body/caseType/variables/version/isActive for template; messageId/inReplyTo/direction/from/to/cc/bcc/subject/body/case/thread/pdfPath/pdfStatus/sentAt/templateId/templateVersion for message; subject/case/messageCount/firstMessageAt/lastMessageAt for thread). Include schema.org annotations (`schema:DigitalDocument`, `schema:EmailMessage`, `schema:Conversation`). Add config keys `email_template_schema`, `email_message_schema`, `email_thread_schema`, plus SMTP/IMAP keys (`email_smtp_host`, `email_smtp_port`, `email_smtp_encryption`, `email_smtp_username`, `email_smtp_password`, `email_from_address`, `email_imap_host`, `email_imap_port`, `email_imap_folder`, `email_transport`, `email_poll_interval`, `email_poll_batch_size`, `email_max_attachment_size`) to `SettingsService.php` CONFIG_KEYS and SLUG_TO_CONFIG_KEY arrays.

### Backend Services

- [ ] **T02**: Create `lib/Service/CaseEmailService.php` — Methods: `sendEmail(caseId, templateId, subject, body, recipients, cc, bcc, attachmentIds)` resolves template variables, generates `Message-ID`, dispatches via configured transport, stores `emailMessage`, triggers PDF conversion, appends `email_sent` activity entry; `processInboundEmail(rawMessage)` parses headers, auto-links by subject regex `\[([A-Z]+-\d{4}-\d{6})\]` or by `In-Reply-To`, stores `emailMessage` and updates/creates `emailThread`, queues unlinked messages; `resolveTemplateVariables(template, case)` returns rendered subject+body with unresolved names listed; `linkUnlinkedEmail(emailId, caseId)` moves an email from queue to case; `discardUnlinkedEmail(emailId, reason)` marks as discarded. Uses ObjectService from OpenRegister, Docudesk for PDF.

- [ ] **T03**: Create `lib/Service/EmailTemplateService.php` — Methods: `createTemplate(caseTypeId, data)` saves new template with `version: 1`; `updateTemplate(templateId, data)` creates a new version object rather than overwriting; `listTemplates(caseTypeId)` returns active templates for case type; `getAvailableVariables(caseTypeId)` returns the variable catalog grouped by source (case, contact, caseType); `seedDefaultTemplates(caseTypeId)` creates `Ontvangstbevestiging`, `Informatieverzoek`, `Besluit`.

### Controllers & Routes

- [ ] **T04**: Create `lib/Controller/CaseEmailController.php` — Authenticated controller with endpoints: `sendEmail(caseId)`, `listEmails(caseId)`, `listUnlinked()`, `linkEmail(emailId)`, `discardEmail(emailId)`, `listTemplates(caseTypeId)`, `createTemplate(caseTypeId)`, `updateTemplate(templateId)`. All methods `@NoAdminRequired`, returns `JSONResponse`.

- [ ] **T05**: Add routes to `appinfo/routes.php` — `POST /api/cases/{caseId}/emails`, `GET /api/cases/{caseId}/emails`, `GET /api/emails/unlinked`, `POST /api/emails/unlinked/{id}/link`, `POST /api/emails/unlinked/{id}/discard`, template CRUD under `/api/casetypes/{caseTypeId}/email-templates` and `/api/email-templates/{templateId}`, settings under `/api/settings/email`. All before SPA catch-all.

### Background Jobs

- [ ] **T06**: Create `lib/BackgroundJob/InboundEmailJob.php` — `TimedJob` with default interval from `email_poll_interval` (default 300 s). Connects to IMAP via `imap_open()` or Nextcloud Mail's account API, fetches unread messages from configured folder (default INBOX), processes up to `email_poll_batch_size` per run (default 50), skips messages whose `Message-ID` already exists, moves processed messages to a "Processed" folder. Catches and logs exceptions without rethrowing.

- [ ] **T07**: Create `lib/BackgroundJob/EmailPdfRetryJob.php` — `TimedJob` (every 15 min) that scans `emailMessage` objects with `pdfStatus: failed` and retries Docudesk conversion up to 3× with exponential backoff (15 min, 1 h, 4 h). Registers both jobs via `IJobList` in `appinfo/info.xml` background-jobs section or `Application::register()`.

### Frontend Components

- [ ] **T08**: Create `src/views/cases/components/EmailComposer.vue` — Modal dialog with recipient (pre-filled from case contact), CC, BCC, subject (pre-filled with `[{{identifier}}]` prefix), rich text body editor, template selector dropdown, attachment picker (case documents), running attachment size, send confirmation step. Validates attachment size against `email_max_attachment_size`. Disabled when case status `isFinal`.

- [ ] **T09**: Create `src/views/cases/components/EmailThread.vue` and `src/views/cases/components/EmailTab.vue` — Tab lists threads grouped collapsible (most recent first) with count badge in tab header; thread renders messages chronologically with direction-distinguished styling (inbound left, outbound right), inline body expand, PDF download link, and per-message Reply action that opens EmailComposer pre-populated.

- [ ] **T10**: Create `src/views/casetypes/components/EmailTemplateAdmin.vue` and `src/views/emails/UnlinkedQueue.vue` — Template admin lists templates per case type with create/edit form, variable sidebar grouped by source (case/contact/caseType), live preview with red-highlighted unresolved variables. UnlinkedQueue lists sender/subject/date/body preview with search-and-link UI plus discard action.

### Settings & Integration

- [ ] **T11**: Create `src/views/settings/EmailSettings.vue` and `lib/Settings/EmailSettings.php` — Admin settings form with SMTP fields (host/port/encryption/username/password/from-address), IMAP fields (host/port/encryption/username/password/folder), transport selector (Nextcloud Mail account picker or standalone SMTP), and "Send test email" button calling `POST /api/settings/email/test-smtp`. Passwords masked and stored via sensitive `IAppConfig` keys. Register settings section in `appinfo/info.xml`.

- [ ] **T12**: Update `src/views/cases/CaseDetail.vue` — Add `EmailTab` to the sidebar tabs in `sidebarProps`. Add "Verstuur email" header action that opens `EmailComposer` (disabled when status `isFinal`). Subscribe to email events to refresh `ActivityTimeline` after send.

## Verification Tasks

- [ ] **V01**: All new schemas valid JSON in `procest_register.json`; load via `openregister:load-register` succeeds
- [ ] **V02**: Routes registered before SPA catch-all and resolve under `/index.php/apps/procest/api/`
- [ ] **V03**: Outbound email is sent, `emailMessage` stored, PDF generated, `email_sent` appears in activity timeline
- [ ] **V04**: Inbound email with `[ZAAK-2026-001234]` subject auto-links to the matching case
- [ ] **V05**: Inbound reply with `In-Reply-To` header is appended to the existing thread
- [ ] **V06**: Unlinked email appears in `/emails/unlinked` and can be manually linked
- [ ] **V07**: Template variables resolve from case data; unresolved variables highlighted red in preview
- [ ] **V08**: Template edit creates a new version; previously sent messages retain `templateVersion`
- [ ] **V09**: Docudesk failure marks `pdfStatus: failed` and retry job re-attempts up to 3×
- [ ] **V10**: SMTP/IMAP passwords stored sensitive; test-email button returns success/failure with specific error
