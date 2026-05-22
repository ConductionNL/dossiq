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

Settings (admin)
└── EmailSettings.vue (SMTP, IMAP, transport choice, test connection)

Inbound (background)
└── InboundEmailJob (TimedJob, configurable interval/batch)
    ├── Auto-link by [ZAAK-YYYY-NNNNNN] subject regex
    ├── Auto-link by In-Reply-To header
    └── Unlinked queue (manual link via /emails/unlinked)

EmailPdfRetryJob (TimedJob, every 15 min)
└── Retry pdfStatus:failed → Docudesk → 3× max with 15m/1h/4h backoff
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
| `lib/Settings/procest_register.json` | Add `emailTemplate`, `emailMessage`, `emailThread` schemas and seed objects |
| `lib/Service/SettingsService.php` | Add `email_*` config keys and SLUG_TO_CONFIG_KEY entries |
| `appinfo/routes.php` | Add email send, template, unlinked queue, and settings routes before SPA catch-all |
| `src/views/cases/CaseDetail.vue` | Add EmailTab to sidebar tabs and "Verstuur email" header action |

## Data Model

### emailTemplate Schema

**Schema.org type:** `schema:DigitalDocument`

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `name` | string | Yes | Template name (e.g. `Ontvangstbevestiging`) |
| `subject` | string | Yes | Subject pattern with `{{variable}}` placeholders |
| `body` | string (HTML) | Yes | Body content with `{{variable}}` placeholders |
| `caseType` | string | Yes | OpenRegister reference to `caseType` object |
| `variables` | array | No | Available variable names scanned from subject + body |
| `version` | integer | No | Auto-incremented on edit (starts at 1) |
| `isActive` | boolean | No | Whether template is selectable in composer (default: true) |

### emailMessage Schema

**Schema.org type:** `schema:EmailMessage`

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `messageId` | string | Yes | RFC 2822 Message-ID (globally unique) |
| `inReplyTo` | string | No | Parent RFC 2822 Message-ID (nullable) |
| `direction` | enum | Yes | `inbound` or `outbound` |
| `from` | string | Yes | Sender email address |
| `to` | array | Yes | Primary recipient email addresses |
| `cc` | array | No | CC recipient addresses |
| `bcc` | array | No | BCC recipient addresses |
| `subject` | string | Yes | Subject line including case prefix |
| `body` | string (HTML) | Yes | Rendered body |
| `case` | string | Yes | OpenRegister reference to `case` object |
| `thread` | string | No | OpenRegister reference to `emailThread` object |
| `pdfPath` | string | No | File path of generated PDF in Nextcloud Files |
| `pdfStatus` | enum | No | `pending`, `completed`, or `failed` (Docudesk conversion state) |
| `sentAt` | datetime | Yes | Send/receive timestamp (ISO 8601) |
| `templateId` | string | No | OpenRegister reference to `emailTemplate` used |
| `templateVersion` | integer | No | Version of template at send time (snapshot) |

### emailThread Schema

**Schema.org type:** `schema:Conversation`

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `subject` | string | Yes | Canonical thread subject (without RE: prefix) |
| `case` | string | Yes | OpenRegister reference to `case` object |
| `messageCount` | integer | No | Total messages in thread (auto-maintained) |
| `firstMessageAt` | datetime | No | Timestamp of first message |
| `lastMessageAt` | datetime | No | Timestamp of most recent message |

## API Design

### Authenticated Endpoints (CaseEmailController)

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/api/cases/{caseId}/emails` | Send email from case (templateId, attachments, cc/bcc optional) |
| `GET` | `/api/cases/{caseId}/emails` | List threads and messages for a case |
| `GET` | `/api/emails/unlinked` | List unlinked inbound emails for manual handling |
| `POST` | `/api/emails/unlinked/{id}/link` | Link an email to a case |
| `POST` | `/api/emails/unlinked/{id}/discard` | Mark unlinked email as discarded |
| `GET` | `/api/casetypes/{caseTypeId}/email-templates` | List templates for case type |
| `POST` | `/api/casetypes/{caseTypeId}/email-templates` | Create template |
| `PUT` | `/api/email-templates/{templateId}` | Update template (creates new version object) |
| `GET` | `/api/settings/email` | Current email configuration |
| `PUT` | `/api/settings/email` | Save SMTP/IMAP/transport settings |
| `POST` | `/api/settings/email/test-smtp` | Send test email |

## Security & Reliability

- IMAP/SMTP passwords stored via `IAppConfig` with `setSensitive(true)` — never appear in logs or audit trails
- Case-number regex anchored as `\[([A-Z]+-\d{4}-\d{6})\]`; matched against subject header only, never body
- Tenant isolation: case lookup by identifier scoped to current organization via OR's `_multitenancy` filter
- Background job catches all exceptions to prevent Nextcloud job deregistration; logs via `LoggerInterface`
- Duplicate detection: `Message-ID` indexed lookup before inserting `emailMessage` record
- PDF conversion runs async via job for messages > 5 MB; sync for smaller messages
- Poller batch size capped at 50 per run (default) and operator-configurable via `email_poll_batch_size`

## Reuse Analysis

Per ADR-012, the following existing platform services are leveraged — no parallel implementations:

| Capability needed | Existing service used | What is NOT rebuilt |
|-------------------|----------------------|---------------------|
| Object CRUD for `emailMessage`, `emailTemplate`, `emailThread` | OpenRegister `ObjectService` | No custom Mapper/Entity classes |
| PDF document generation | Docudesk integration (existing in procest) | No custom PDF renderer |
| File storage (PDFs as case documents) | `FileService` + `caseDocument` schema | No custom file upload controller |
| Audit trail for sent/received emails | OpenRegister audit trail (automatic on all OR objects) | No custom `EmailAuditService` |
| Activity feed events | OpenRegister `ActivityService` (existing in procest) | No custom activity log table |
| Admin settings persistence | `IAppConfig` + existing `SettingsService` pattern | No new database table for config |
| Background job scheduling | Nextcloud `IJobList` + `TimedJob` (existing pattern in procest) | No custom scheduler |
| RBAC — who can send email from a case | OpenRegister per-object RBAC (existing) | No custom permission service |

New code in this change is limited to: SMTP/IMAP transport logic, RFC 2822 header parsing, template variable resolution, and the Vue composer/thread UI — all domain-specific business logic without platform equivalents.

## Seed Data

Per ADR-001 (seed data requirements), the following objects MUST be included in `procest_register.json` under `components.objects[]` using the `@self` envelope.

### emailTemplate — 3 seed objects

```json
{
  "@self": {
    "register": "procest",
    "schema": "emailTemplate",
    "slug": "email-template-ontvangstbevestiging"
  },
  "name": "Ontvangstbevestiging",
  "subject": "[{{case.identifier}}] Ontvangstbevestiging — {{case.title}}",
  "body": "<p>Geachte {{contact.salutation}},</p><p>Hierbij bevestigen wij de ontvangst van uw aanvraag <strong>{{case.title}}</strong> (zaaknummer {{case.identifier}}).</p><p>Uw aanvraag is geregistreerd op {{case.startDate}}. De wettelijke beslistermijn bedraagt {{caseType.processingDeadline}}.</p><p>Met vriendelijke groet,<br>Gemeente Westerhaven</p>",
  "caseType": "ref:caseType:omgevingsvergunning-regulier",
  "variables": ["case.identifier", "case.title", "case.startDate", "contact.salutation", "caseType.processingDeadline"],
  "version": 1,
  "isActive": true
}
```

```json
{
  "@self": {
    "register": "procest",
    "schema": "emailTemplate",
    "slug": "email-template-informatieverzoek"
  },
  "name": "Informatieverzoek",
  "subject": "[{{case.identifier}}] Verzoek om aanvullende informatie — {{case.title}}",
  "body": "<p>Geachte {{contact.salutation}},</p><p>In het kader van uw aanvraag <strong>{{case.title}}</strong> ({{case.identifier}}) verzoeken wij u de volgende informatie aan te leveren vóór {{task.dueDate}}:</p><ul><li></li></ul><p>Zonder de gevraagde informatie kunnen wij uw aanvraag niet verder in behandeling nemen.</p><p>Met vriendelijke groet,<br>Gemeente Westerhaven</p>",
  "caseType": "ref:caseType:omgevingsvergunning-regulier",
  "variables": ["case.identifier", "case.title", "contact.salutation", "task.dueDate"],
  "version": 1,
  "isActive": true
}
```

```json
{
  "@self": {
    "register": "procest",
    "schema": "emailTemplate",
    "slug": "email-template-besluit"
  },
  "name": "Besluit",
  "subject": "[{{case.identifier}}] Besluit op uw aanvraag — {{case.title}}",
  "body": "<p>Geachte {{contact.salutation}},</p><p>Op uw aanvraag <strong>{{case.title}}</strong> ({{case.identifier}}) heeft het college van burgemeester en wethouders op {{decision.decisionDate}} een besluit genomen.</p><p>Het besluit luidt: <strong>{{decision.title}}</strong>.</p><p>Het volledige besluit treft u aan als bijlage bij dit bericht. Tegen dit besluit kunt u binnen zes weken bezwaar maken.</p><p>Met vriendelijke groet,<br>Gemeente Westerhaven</p>",
  "caseType": "ref:caseType:omgevingsvergunning-regulier",
  "variables": ["case.identifier", "case.title", "contact.salutation", "decision.decisionDate", "decision.title"],
  "version": 1,
  "isActive": true
}
```

### emailThread — 3 seed objects

```json
{
  "@self": {
    "register": "procest",
    "schema": "emailThread",
    "slug": "email-thread-zaak-2026-000142"
  },
  "subject": "Ontvangstbevestiging — Verbouwing woning Keizersgracht 47",
  "case": "ref:case:omgevingsvergunning-keizersgracht-47",
  "messageCount": 2,
  "firstMessageAt": "2026-03-10T09:15:00+01:00",
  "lastMessageAt": "2026-03-12T14:32:00+01:00"
}
```

```json
{
  "@self": {
    "register": "procest",
    "schema": "emailThread",
    "slug": "email-thread-zaak-2026-000198"
  },
  "subject": "Informatieverzoek — Uitbouw achtergevel Hoofdstraat 12",
  "case": "ref:case:omgevingsvergunning-hoofdstraat-12",
  "messageCount": 3,
  "firstMessageAt": "2026-04-01T11:05:00+02:00",
  "lastMessageAt": "2026-04-08T16:20:00+02:00"
}
```

```json
{
  "@self": {
    "register": "procest",
    "schema": "emailThread",
    "slug": "email-thread-zaak-2026-000073"
  },
  "subject": "Besluit op uw aanvraag — Dakkapel Tulpstraat 3",
  "case": "ref:case:omgevingsvergunning-tulpstraat-3",
  "messageCount": 1,
  "firstMessageAt": "2026-02-28T15:00:00+01:00",
  "lastMessageAt": "2026-02-28T15:00:00+01:00"
}
```

### emailMessage — 4 seed objects

```json
{
  "@self": {
    "register": "procest",
    "schema": "emailMessage",
    "slug": "email-message-out-keizersgracht-01"
  },
  "messageId": "<procest.2026.03.10.0915.a1b2c3@gemeente-westerhaven.nl>",
  "inReplyTo": null,
  "direction": "outbound",
  "from": "zaken@gemeente-westerhaven.nl",
  "to": ["j.de.vries@example.nl"],
  "cc": [],
  "bcc": [],
  "subject": "[ZAAK-2026-000142] Ontvangstbevestiging — Verbouwing woning Keizersgracht 47",
  "body": "<p>Geachte de heer De Vries,</p><p>Hierbij bevestigen wij de ontvangst van uw aanvraag...</p>",
  "case": "ref:case:omgevingsvergunning-keizersgracht-47",
  "thread": "ref:emailThread:email-thread-zaak-2026-000142",
  "pdfPath": "/Procest/Emails/2026/03/email-out-keizersgracht-01.pdf",
  "pdfStatus": "completed",
  "sentAt": "2026-03-10T09:15:00+01:00",
  "templateId": "ref:emailTemplate:email-template-ontvangstbevestiging",
  "templateVersion": 1
}
```

```json
{
  "@self": {
    "register": "procest",
    "schema": "emailMessage",
    "slug": "email-message-in-keizersgracht-02"
  },
  "messageId": "<CAx7z9q8_reply@mail.example.nl>",
  "inReplyTo": "<procest.2026.03.10.0915.a1b2c3@gemeente-westerhaven.nl>",
  "direction": "inbound",
  "from": "j.de.vries@example.nl",
  "to": ["zaken@gemeente-westerhaven.nl"],
  "cc": [],
  "bcc": [],
  "subject": "Re: [ZAAK-2026-000142] Ontvangstbevestiging — Verbouwing woning Keizersgracht 47",
  "body": "<p>Geachte behandelaar, hartelijk dank voor de bevestiging. Kunt u mij informeren over de doorlooptijd?</p>",
  "case": "ref:case:omgevingsvergunning-keizersgracht-47",
  "thread": "ref:emailThread:email-thread-zaak-2026-000142",
  "pdfPath": "/Procest/Emails/2026/03/email-in-keizersgracht-02.pdf",
  "pdfStatus": "completed",
  "sentAt": "2026-03-12T14:32:00+01:00",
  "templateId": null,
  "templateVersion": null
}
```

```json
{
  "@self": {
    "register": "procest",
    "schema": "emailMessage",
    "slug": "email-message-out-hoofdstraat-01"
  },
  "messageId": "<procest.2026.04.01.1105.d4e5f6@gemeente-westerhaven.nl>",
  "inReplyTo": null,
  "direction": "outbound",
  "from": "zaken@gemeente-westerhaven.nl",
  "to": ["m.bakker@bouwbedrijfbakker.nl"],
  "cc": ["vergunningen@gemeente-westerhaven.nl"],
  "bcc": [],
  "subject": "[ZAAK-2026-000198] Verzoek om aanvullende informatie — Uitbouw achtergevel Hoofdstraat 12",
  "body": "<p>Geachte mevrouw Bakker,</p><p>In het kader van uw aanvraag verzoeken wij u...</p>",
  "case": "ref:case:omgevingsvergunning-hoofdstraat-12",
  "thread": "ref:emailThread:email-thread-zaak-2026-000198",
  "pdfPath": "/Procest/Emails/2026/04/email-out-hoofdstraat-01.pdf",
  "pdfStatus": "completed",
  "sentAt": "2026-04-01T11:05:00+02:00",
  "templateId": "ref:emailTemplate:email-template-informatieverzoek",
  "templateVersion": 1
}
```

```json
{
  "@self": {
    "register": "procest",
    "schema": "emailMessage",
    "slug": "email-message-out-tulpstraat-besluit"
  },
  "messageId": "<procest.2026.02.28.1500.g7h8i9@gemeente-westerhaven.nl>",
  "inReplyTo": null,
  "direction": "outbound",
  "from": "zaken@gemeente-westerhaven.nl",
  "to": ["p.smit@example.com"],
  "cc": [],
  "bcc": [],
  "subject": "[ZAAK-2026-000073] Besluit op uw aanvraag — Dakkapel Tulpstraat 3",
  "body": "<p>Geachte de heer Smit,</p><p>Op uw aanvraag heeft het college op 28 februari 2026 een besluit genomen...</p>",
  "case": "ref:case:omgevingsvergunning-tulpstraat-3",
  "thread": "ref:emailThread:email-thread-zaak-2026-000073",
  "pdfPath": "/Procest/Emails/2026/02/email-out-tulpstraat-besluit.pdf",
  "pdfStatus": "completed",
  "sentAt": "2026-02-28T15:00:00+01:00",
  "templateId": "ref:emailTemplate:email-template-besluit",
  "templateVersion": 1
}
```
