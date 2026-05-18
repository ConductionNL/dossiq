# Tasks: case-email-integration

## Implementation Tasks

### Schema, Configuration, Settings

- [ ] **T01**: Add `emailTemplate`, `emailMessage`, `emailThread` schemas to `lib/Settings/procest_register.json` (schema.org annotations `schema:DigitalDocument`, `schema:EmailMessage`, `schema:Conversation`; fields per design). Add config keys (`email_template_schema`, `email_message_schema`, `email_thread_schema`, full SMTP/IMAP/transport/polling/attachment-size set) to `SettingsService.php` `CONFIG_KEYS` and `SLUG_TO_CONFIG_KEY`.
- [ ] **T02**: Create `lib/Settings/EmailSettings.php` + `src/views/settings/EmailSettings.vue` — Admin form with SMTP fields, IMAP fields, transport selector (Nextcloud Mail account picker or standalone SMTP), and a "Send test email" button hitting `POST /api/settings/email/test-smtp`. Passwords masked, stored as sensitive `IAppConfig` keys. Register settings section in `appinfo/info.xml`.

### Backend Services

- [ ] **T03**: Create `lib/Service/CaseEmailService.php` — `sendEmail()` resolves template variables, generates `Message-ID`, dispatches via configured transport, stores `emailMessage`, triggers PDF conversion, appends `email_sent` activity entry. `processInboundEmail()` parses headers, auto-links by `\[([A-Z]+-\d{4}-\d{6})\]` subject regex or `In-Reply-To`, stores `emailMessage` + updates/creates `emailThread`, queues unlinked. Plus `resolveTemplateVariables()`, `linkUnlinkedEmail()`, `discardUnlinkedEmail()`. Uses OpenRegister ObjectService + Docudesk for PDF.
- [ ] **T04**: Create `lib/Service/EmailTemplateService.php` — `createTemplate()` saves with `version: 1`; `updateTemplate()` creates a new version object (no overwrite); `listTemplates()`, `getAvailableVariables()` grouped by source (case/contact/caseType), `seedDefaultTemplates()` creates `Ontvangstbevestiging`, `Informatieverzoek`, `Besluit`.

### Controllers, Routes, Background Jobs

- [ ] **T05**: Create `lib/Controller/CaseEmailController.php` (sendEmail/listEmails/listUnlinked/linkEmail/discardEmail/listTemplates/createTemplate/updateTemplate, all `@NoAdminRequired`) and add routes to `appinfo/routes.php` (`/api/cases/{caseId}/emails`, `/api/emails/unlinked[/{id}/{link,discard}]`, template CRUD under `/api/casetypes/{caseTypeId}/email-templates` + `/api/email-templates/{templateId}`, `/api/settings/email`). All before SPA catch-all.
- [ ] **T06**: Create `lib/BackgroundJob/InboundEmailJob.php` (`TimedJob`, interval from `email_poll_interval` default 300s; connects via IMAP or Nextcloud Mail account API, processes up to `email_poll_batch_size` unread messages, dedupes by `Message-ID`, moves processed to "Processed" folder, swallows + logs exceptions) and `lib/BackgroundJob/EmailPdfRetryJob.php` (every 15min, retries `pdfStatus: failed` up to 3× with 15m/1h/4h backoff). Register both via `IJobList` in `Application::register()` or `appinfo/info.xml`.

### Frontend Components

- [ ] **T07**: Create `src/views/cases/components/EmailComposer.vue` — Modal with recipient prefilled from case contact, CC/BCC, subject prefilled with `[{{identifier}}]`, rich-text body, template dropdown, case-document attachment picker with running size + `email_max_attachment_size` validation, send confirmation. Disabled when case status `isFinal`.
- [ ] **T08**: Create `src/views/cases/components/EmailThread.vue` and `EmailTab.vue` — Tab lists threads grouped collapsible (most recent first) with count badge, messages chronological with inbound/outbound styling, inline body expand, PDF download link, per-message Reply opens prefilled EmailComposer.
- [ ] **T09**: Create `src/views/casetypes/components/EmailTemplateAdmin.vue` (per-case-type CRUD form, variable sidebar grouped by source, live preview with red-highlighted unresolved variables) and `src/views/emails/UnlinkedQueue.vue` (sender/subject/date/body preview with search-and-link + discard).
- [ ] **T10**: Update `src/views/cases/CaseDetail.vue` — Add `EmailTab` to sidebar tabs in `sidebarProps`. Add "Verstuur email" header action that opens `EmailComposer` (disabled when status `isFinal`). Subscribe to email events to refresh `ActivityTimeline`.

## Verification Tasks

- [ ] **V01**: Schemas + routes load: `procest_register.json` valid, `openregister:load-register` succeeds, all routes resolve under `/index.php/apps/procest/api/` before SPA catch-all.
- [ ] **V02**: Outbound flow end-to-end: send produces stored `emailMessage`, PDF generated, `email_sent` shown in activity timeline; Docudesk failure marks `pdfStatus: failed` + retry job re-attempts up to 3×.
- [ ] **V03**: Inbound + threading: subject-tagged inbound auto-links; `In-Reply-To` appends to existing thread; unrecognised mail surfaces in `/emails/unlinked` and can be manually linked.
- [ ] **V04**: Template variables resolve from case data with red-highlighted unresolved in preview; template edit creates a new version while previously sent messages retain their original `templateVersion`.
- [ ] **V05**: SMTP/IMAP credentials stored sensitive; "Send test email" returns success/failure with specific error message.
