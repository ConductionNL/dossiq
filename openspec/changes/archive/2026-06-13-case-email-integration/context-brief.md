# Proposal: case-email-integration

## Summary

Integrate inbound and outbound email directly into the Procest case workflow. Email becomes a first-class case interaction: outbound mail is composed from case context with templates and case-data variable resolution, all sent and received messages are converted to PDF and stored as case documents (`caseDocument`), and threading via RFC 2822 headers keeps conversations grouped per case. The integration supports both Nextcloud Mail accounts and standalone SMTP/IMAP transports, plus a manual queue for unlinked inbound messages.

## Motivation

Email remains the primary correspondence channel between municipalities and citizens, applicants, and partner organizations. Today, that correspondence sits in personal mailboxes outside the case system, breaking the audit trail and making it impossible to reconstruct a case after the fact. ZGW and Archiefwet require case correspondence to be archived as informatieobjecten. This change closes the gap: every sent/received email is captured, classified, threaded, and surfaced in the case detail view and the activity timeline.

## Affected Projects

- [ ] Project: `procest` — Backend services, controllers, background jobs, schemas, and Vue components for email integration

## Scope

### In Scope

- **Outbound email** — Compose with templates, variable resolution, attachment picker from case documents, CC/BCC, send confirmation
- **Inbound email** — IMAP polling background job, auto-linking by case-number regex and `In-Reply-To`, unlinked queue with manual link/discard
- **Email templates per case type** — CRUD, versioning, variable sidebar, default Dutch templates (`Ontvangstbevestiging`, `Informatieverzoek`, `Besluit`)
- **Threading** — `Message-ID` / `In-Reply-To`, `emailThread` objects, chronological view in case detail
- **Email-to-PDF** — Docudesk integration, retry on failure, large-email background conversion, storage as `caseDocument`
- **Activity timeline** — `email_sent` / `email_received` events with timestamps and recipients
- **Admin settings** — SMTP and IMAP configuration with connection test, Nextcloud Mail transport option

### Out of Scope

- Real-time push notifications from IMAP IDLE (polling only)
- Calendar/iCalendar handling of meeting invitations
- Encrypted (S/MIME, PGP) email
- DMARC/SPF/DKIM authentication of inbound mail (delegated to mail server)

## Approach

1. Add `emailTemplate`, `emailMessage`, `emailThread` schemas to `procest_register.json`
2. Create `CaseEmailService` for send/receive, template variable resolution, threading, PDF conversion
3. Create `CaseEmailController` (auth) and routes for compose, template, queue endpoints
4. Add `InboundEmailJob` `TimedJob` for IMAP polling (configurable interval, batch size)
5. Vue: `EmailComposer.vue`, `EmailTemplateAdmin.vue`, `EmailThread.vue`, email tab in `CaseDetail.vue`
6. Extend `SettingsService` with `email_*` config keys (SMTP, IMAP, transport choice)

## Risks

- IMAP credentials must be stored encrypted via Nextcloud credential store
- Auto-linking regex must not match attacker-controlled subjects from other tenants
- Docudesk failures must not block email reception — retry asynchronously
- Large attachments and embedded images can exhaust memory if processed synchronously



## Design

# Design: case-email-integration

## Architecture Overview

Email integration adds a correspondence layer on top of the existing case management infrastructure. Outbound mail flows through a `CaseEmailService` that resolves template variables, renders the email, sends via SMTP or Nextcloud Mail, and stores the message as an `emailMessage` object plus a PDF `caseDocument`. Inbound mail flows through an `InboundEmailJob` IMAP poller that auto-links by case number or thread headers, or queues unlinked messages for manual handling. Threading is maintained via RFC 2822 `Message-ID` / `In-Reply-To` headers stored on `emailMessage`, with thread aggregation in `emailThread` objects.

```
CaseDetail.vue
├── EmailTab (new sidebar tab)
│   ├── EmailThread.vue (chronological message view per thread)
│   └── EmailComposer.vue (send/reply dialog)
└── ActivityTimeline.vue (existing, extended with email_sent/email_received events)

CaseTypeDetail.vue
└── EmailTemplateAdmin.vue (template CRUD per case type)

Settings
├── EmailSettings.vue (SMTP, IMAP, transport choice, test connection)

Inbound (background)
└── InboundEmailJob (TimedJob, configurable interval/batch)
    ├── Auto-link by [ZAAK-YYYY-NNNNNN] subject regex
    ├── Auto-link by In-Reply-To header
    └── Unlinked queue (manual link via /emails/unlinked)
```

## File Map

### New Backend Files

| File | Purpose |
|------|---------|
| `lib/Service/CaseEmailService.php` | Send outbound, process inbound, resolve template variables, RFC 2822 threading, Docudesk PDF orchestration |
| `lib/Service/EmailTemplateService.php` | Template CRUD, versioning on edit, variable catalog generation |
| `lib/Controller/CaseEmailController.php` | Authenticated API: send, list threads, list unlinked, link/discard, template CRUD |
| `lib/BackgroundJob/InboundEmailJob.php` | TimedJob: IMAP poll, auto-link, batch size limit, duplicate detection via Message-ID |
| `lib/BackgroundJob/EmailPdfRetryJob.php` | TimedJob: retry failed Docudesk conversions up to 3× with exponential backoff |
| `lib/Settings/EmailSettings.php` | Admin settings section registration |

### New Frontend Files

| File | Purpose |
|------|---------|
| `src/views/cases/components/EmailTab.vue` | Case detail tab grouping messages by thread with count badge |
| `src/views/cases/components/EmailComposer.vue` | Modal: recipient/CC/BCC, subject, body (rich text), template selector, attachment picker, send confirmation |
| `src/views/cases/components/EmailThread.vue` | Chronological thread renderer (inbound left, outbound right) with inline expand |
| `src/views/casetypes/components/EmailTemplateAdmin.vue` | Template CRUD per case type with variable sidebar and live preview |
| `src/views/emails/UnlinkedQueue.vue` | Standalone view for manual linking of unlinked inbound emails |
| `src/views/settings/EmailSettings.vue` | SMTP/IMAP/transport config form with connection test |

### Modified Files

| File | Changes |
|------|---------|
| `lib/Settings/procest_register.json` | Add `emailTemplate`, `emailMessage`, `emailThread` schemas |
| `lib/Service/SettingsService.php` | Add `email_*` config keys and SLUG_TO_CONFIG_KEY entries |
| `appinfo/routes.php` | Add email send, template, unlinked queue, settings routes |
| `src/views/cases/CaseDetail.vue` | Add EmailTab to sidebar tabs and "Verstuur email" header action |

## Data Model

### emailTemplate Schema
- `name` (string, required) — Template name (e.g., `Ontvangstbevestiging`)
- `subject` (string, required) — Subject pattern with `{{variable}}` placeholders
- `body` (string/HTML, required) — Body content with `{{variable}}` placeholders
- `caseType` (string/reference, required) — Reference to case type
- `variables` (array of strings) — Available variable names
- `version` (integer, default 1) — Bumped on edit; previously sent emails retain their version
- `isActive` (boolean, default true) — Whether template is selectable

### emailMessage Schema
- `messageId` (string, RFC 2822) — Unique message identifier
- `inReplyTo` (string, nullable) — Parent message identifier
- `direction` (enum: inbound/outbound) — Send direction
- `from` (string) — Sender email address
- `to` (array of strings) — Primary recipients
- `cc` (array of strings) — CC recipients
- `bcc` (array of strings) — BCC recipients
- `subject` (string) — Subject line including case prefix
- `body` (string/HTML) — Rendered body
- `case` (string/reference) — Reference to case object
- `thread` (string/reference) — Reference to emailThread
- `pdfPath` (string) — File path of generated PDF in Nextcloud Files
- `pdfStatus` (enum: pending/completed/failed) — Docudesk conversion status
- `sentAt` (datetime) — Send/receive timestamp
- `templateId` (string, nullable) — Reference to emailTemplate used
- `templateVersion` (integer, nullable) — Version of template at send time

### emailThread Schema
- `subject` (string) — Thread subject (without RE: prefix)
- `case` (string/reference) — Reference to case object
- `messageCount` (integer) — Total messages in thread
- `firstMessageAt` (datetime) — Timestamp of first message
- `lastMessageAt` (datetime) — Timestamp of most recent message

## API Design

### Authenticated Endpoints (CaseEmailController)
- `POST /api/cases/{caseId}/emails` — Send email from case (with optional templateId, attachments, cc/bcc)
- `GET /api/cases/{caseId}/emails` — List threads and messages for a case
- `GET /api/emails/unlinked` — List unlinked inbound emails for manual handling
- `POST /api/emails/unlinked/{id}/link` — Link an email to a case
- `POST /api/emails/unlinked/{id}/discard` — Mark unlinked email as discarded
- `GET /api/casetypes/{caseTypeId}/email-templates` — List templates for case type
- `POST /api/casetypes/{caseTypeId}/email-templates` — Create template
- `PUT /api/email-templates/{templateId}` — Update template (creates new version)

### Settings Endpoints
- `GET /api/settings/email` — Current email configuration
- `PUT /api/settings/email` — Save SMTP/IMAP/transport settings
- `POST /api/settings/email/test-smtp` — Send test email

## Security & Reliability

- IMAP/SMTP passwords stored via `IAppConfig` with `setSensitive(true)` or Nextcloud credential store
- Case-number regex anchored as `\[([A-Z]+-\d{4}-\d{6})\]`; subject-only, not body
- Tenant isolation: lookup of cases by identifier scoped to current organization
- Background job catches exceptions to avoid deregistration; logs via `LoggerInterface`
- Duplicate detection by `messageId` indexed lookup before inserting `emailMessage`
- PDF conversion runs async via job for messages > 5 MB; sync for smaller messages
- Rate limiting: poller batch size capped (default 50) and configurable



## Tasks

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