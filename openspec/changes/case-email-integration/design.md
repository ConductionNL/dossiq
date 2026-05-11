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
