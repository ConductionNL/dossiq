---
status: proposed
---

# Spec: case-email-integration

**Status:** proposed
**Scope:** procest
**Depends on:** case-management, case-types, admin-settings, openregister (ObjectService + audit + RBAC per ADR-022), docudesk (PDF conversion)

## ADDED Requirements

---

### REQ-CEI-001: The system SHALL store email entities as OpenRegister objects — no parallel storage

Three schemas MUST be declared in `lib/Settings/procest_register.json`:

- `emailTemplate` (`schema:DigitalDocument`) — reusable templates per case type with `{{variable}}` placeholders and versioning
- `emailMessage` (`schema:EmailMessage`) — individual sent/received messages with RFC 2822 threading metadata and Docudesk PDF status
- `emailThread` (`schema:Conversation`) — conversation group linking messages to a case

No custom PHP Entity, Mapper, or database table MAY be created for these entities. All storage flows through OpenRegister `ObjectService` (ADR-022 anti-pattern: no parallel storage).

**emailTemplate fields** (all others from ADR-000 built-ins):

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | Yes | Display name, e.g. `Ontvangstbevestiging` |
| `subject` | string | Yes | Subject pattern with `{{variable}}` placeholders |
| `body` | string (HTML) | Yes | Body with `{{variable}}` placeholders |
| `caseType` | string | Yes | OR reference to `caseType` |
| `variables` | array | No | Variable names present in subject + body |
| `version` | integer | No | Incremented on each edit (starts at 1) |
| `isActive` | boolean | No | Whether selectable in composer (default: true) |

**emailMessage fields:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `messageId` | string | Yes | RFC 2822 globally unique identifier |
| `inReplyTo` | string | No | Parent RFC 2822 Message-ID |
| `direction` | enum | Yes | `inbound` or `outbound` |
| `from` | string | Yes | Sender address |
| `to` | array | Yes | Primary recipient addresses |
| `cc` | array | No | CC addresses |
| `bcc` | array | No | BCC addresses |
| `subject` | string | Yes | Subject including case prefix |
| `body` | string (HTML) | Yes | Rendered body |
| `case` | string | Yes | OR reference to `case` (null for unlinked inbound) |
| `thread` | string | No | OR reference to `emailThread` |
| `pdfPath` | string | No | Path of generated PDF in Nextcloud Files |
| `pdfStatus` | enum | No | `pending`, `completed`, or `failed` |
| `sentAt` | datetime | Yes | Send/receive timestamp (ISO 8601) |
| `templateId` | string | No | OR reference to `emailTemplate` used |
| `templateVersion` | integer | No | Version of template at send time (snapshot) |

**emailThread fields:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `subject` | string | Yes | Canonical subject (RE: prefix stripped) |
| `case` | string | Yes | OR reference to `case` |
| `messageCount` | integer | No | Total messages in thread |
| `firstMessageAt` | datetime | No | Timestamp of first message |
| `lastMessageAt` | datetime | No | Timestamp of most recent message |

#### Scenario: Schemas load without errors

- **GIVEN** procest is installed and `procest_register.json` contains the three new schemas
- **WHEN** `openregister:load-register` is executed
- **THEN** all three schemas MUST be created without validation errors and be accessible via the OR object API

#### Scenario: Reviewer confirms no parallel storage

- **GIVEN** the procest codebase after this change
- **WHEN** scanned for files matching `lib/Db/*email*`, `lib/Entity/*Email*`, or `lib/Mapper/*Email*`
- **THEN** no such files SHALL exist; all email data flows through OpenRegister

---

### REQ-CEI-002: The system SHALL send outbound email via `CaseEmailService` with template variable resolution

`lib/Service/CaseEmailService.php` MUST implement `sendEmail(caseId, templateId, subject, body, recipients, cc, bcc, attachmentIds)`. The method MUST:

1. Resolve `{{variable}}` placeholders from case, contact, and caseType data
2. Generate a unique RFC 2822 `Message-ID` header
3. Dispatch via the configured transport (standalone SMTP or Nextcloud Mail account)
4. Store the sent message as an `emailMessage` OR object with `direction: outbound`
5. Find or create an `emailThread` object for the conversation
6. Append an `email_sent` activity entry to the case's `activity` field
7. Trigger Docudesk PDF conversion (async for messages > 5 MB, sync otherwise)

All controller methods MUST remain thin (<10 lines per ADR-003); business logic lives in the service.

#### Scenario: Outbound email is sent and stored

- **GIVEN** case `ZAAK-2026-000142` with contact `j.de.vries@example.nl`
- **WHEN** a handler calls `sendEmail()` with template `Ontvangstbevestiging`
- **THEN** an `emailMessage` MUST be stored with `direction: outbound` and `sentAt` set
- **AND** an `email_sent` event MUST appear in the case `activity` array with recipient address and timestamp

#### Scenario: Template variables resolve before sending

- **GIVEN** template body `Geachte {{contact.salutation}}, zaaknummer {{case.identifier}}`
- **WHEN** `resolveTemplateVariables()` is called for case `ZAAK-2026-000142`
- **THEN** the rendered body MUST contain the actual salutation and identifier — never raw `{{...}}` tokens

#### Scenario: Unresolved variables are returned, not sent blind

- **GIVEN** a template containing `{{case.nonExistentField}}`
- **WHEN** `resolveTemplateVariables()` processes it
- **THEN** the method MUST return the list of unresolved names so the frontend highlights them in red; the email MUST NOT be dispatched with raw placeholder tokens to the recipient

#### Scenario: Composer is disabled for a final-status case

- **GIVEN** a case with `isFinal: true` on its current status
- **WHEN** a handler views the email tab
- **THEN** `EmailComposer.vue` MUST be fully disabled with an explanatory message; `sendEmail()` MUST reject the call server-side as well

---

### REQ-CEI-003: The system SHALL poll inbound email and auto-link messages to cases via `InboundEmailJob`

`lib/BackgroundJob/InboundEmailJob.php` MUST be a `TimedJob` (interval from `email_poll_interval`, default 300 s). Per run:

1. Connect to configured IMAP server (or Nextcloud Mail account API)
2. Fetch up to `email_poll_batch_size` (default 50) unread messages from the configured folder
3. Skip messages whose `Message-ID` already exists as an `emailMessage` object (duplicate detection)
4. Auto-link by matching `\[([A-Z]+-\d{4}-\d{6})\]` in the **subject header only** against cases scoped to the current organization
5. Auto-link by matching `In-Reply-To` against existing `emailMessage.messageId` values
6. Store each message as `emailMessage` with `direction: inbound`; update or create `emailThread`
7. Move processed messages to the "Processed" IMAP folder
8. Queue unlinked messages (no case match) with `case: null` for manual handling
9. Catch all exceptions without rethrowing; log via `LoggerInterface` to prevent job deregistration

#### Scenario: Subject-tagged inbound email auto-links

- **GIVEN** an email with subject `[ZAAK-2026-000142] Vraag over mijn vergunning`
- **WHEN** `InboundEmailJob` runs and the regex matches case `ZAAK-2026-000142`
- **THEN** an `emailMessage` MUST be stored with `case` referencing that case and `direction: inbound`
- **AND** an `email_received` event MUST appear in the case activity array

#### Scenario: Reply email threads via `In-Reply-To`

- **GIVEN** an inbound email with `In-Reply-To: <procest.2026.03.10.0915.a1b2c3@gemeente-westerhaven.nl>`
- **WHEN** that Message-ID matches an existing `emailMessage.messageId`
- **THEN** the new `emailMessage` MUST reference the same `thread` as the parent message

#### Scenario: Unmatched email is queued for manual handling

- **GIVEN** an inbound email with no recognizable case tag and no matching `In-Reply-To`
- **WHEN** `InboundEmailJob` processes it
- **THEN** an `emailMessage` MUST be stored with `case: null` and appear in `GET /api/emails/unlinked`

#### Scenario: Duplicate message is skipped

- **GIVEN** `emailMessage` with `messageId: <CAx7z9q8@mail.example.nl>` already exists in OpenRegister
- **WHEN** `InboundEmailJob` encounters the same RFC 2822 Message-ID during polling
- **THEN** the message MUST NOT be stored again; the job MUST continue processing remaining messages

---

### REQ-CEI-004: The system SHALL maintain `emailThread` aggregation on every message store or update

When any `emailMessage` is stored, `CaseEmailService` MUST find or create an `emailThread` linked to the same case. The thread MUST be updated:

- `messageCount` incremented by 1
- `lastMessageAt` set to the current message's `sentAt`
- `firstMessageAt` set only on thread creation

Thread `subject` is the canonical subject with `Re:`, `Fw:`, and case-tag prefixes stripped.

#### Scenario: Thread created on first message

- **GIVEN** no thread exists for case `ZAAK-2026-000142`
- **WHEN** the first outbound email is sent
- **THEN** an `emailThread` MUST be created with `messageCount: 1` and `firstMessageAt` equal to `sentAt`

#### Scenario: Thread count updated on reply

- **GIVEN** a thread with `messageCount: 2`
- **WHEN** a new inbound reply is processed
- **THEN** the thread MUST update to `messageCount: 3` and `lastMessageAt` set to the new `sentAt`

---

### REQ-CEI-005: The system SHALL convert every email to PDF via Docudesk and store as `caseDocument`

Every `emailMessage` (inbound and outbound) MUST be converted to PDF by the existing Docudesk integration. The PDF is stored at `pdfPath` and registered as a `caseDocument` linked to the case. `pdfStatus` tracks state: `pending` → `completed` or `failed`.

Conversion is synchronous for messages ≤ 5 MB; asynchronous (set `pdfStatus: pending`) for larger messages. `EmailPdfRetryJob` (every 15 min) retries `pdfStatus: failed` objects up to 3× with exponential backoff (15 min, 1 h, 4 h).

#### Scenario: Docudesk failure does not block email storage or delivery

- **GIVEN** Docudesk is temporarily unavailable
- **WHEN** `sendEmail()` dispatches an outbound message
- **THEN** the `emailMessage` MUST be stored with `pdfStatus: failed`
- **AND** the email MUST still be delivered to the recipient via SMTP

#### Scenario: Retry job re-attempts failed conversions

- **GIVEN** three `emailMessage` objects with `pdfStatus: failed`
- **WHEN** `EmailPdfRetryJob` runs and Docudesk is available
- **THEN** all three MUST be retried; successful conversions MUST set `pdfStatus: completed`

---

### REQ-CEI-006: The system SHALL version email templates on edit — old versions are retained, not overwritten

`EmailTemplateService::updateTemplate(templateId, data)` MUST create a **new** `emailTemplate` OR object with `version` incremented. The previous version MUST remain so that existing `emailMessage` objects can reference their original `templateVersion`. Overwriting the existing object is forbidden.

#### Scenario: Template update creates new version, old version retained

- **GIVEN** template `Ontvangstbevestiging` at `version: 1` used in 5 sent emails
- **WHEN** an admin updates the body via `updateTemplate()`
- **THEN** a new `emailTemplate` with `version: 2` MUST be created
- **AND** the `version: 1` object MUST still exist with unchanged content

#### Scenario: Sent messages retain their template version snapshot

- **GIVEN** an `emailMessage` sent with `templateVersion: 1`
- **WHEN** the template is updated to `version: 2`
- **THEN** the `emailMessage.templateVersion` MUST still read `1`

---

### REQ-CEI-007: The system SHALL expose all email operations through `CaseEmailController` with routes before the SPA catch-all

`lib/Controller/CaseEmailController.php` is an authenticated Nextcloud controller (`@NoAdminRequired` on all methods). Endpoints:

| Method | Path | Handler |
|--------|------|---------|
| `POST` | `/api/cases/{caseId}/emails` | `sendEmail` |
| `GET` | `/api/cases/{caseId}/emails` | `listEmails` |
| `GET` | `/api/emails/unlinked` | `listUnlinked` |
| `POST` | `/api/emails/unlinked/{id}/link` | `linkEmail` |
| `POST` | `/api/emails/unlinked/{id}/discard` | `discardEmail` |
| `GET` | `/api/casetypes/{caseTypeId}/email-templates` | `listTemplates` |
| `POST` | `/api/casetypes/{caseTypeId}/email-templates` | `createTemplate` |
| `PUT` | `/api/email-templates/{templateId}` | `updateTemplate` |
| `GET` | `/api/settings/email` | `getSettings` |
| `PUT` | `/api/settings/email` | `saveSettings` |
| `POST` | `/api/settings/email/test-smtp` | `testSmtp` |

All routes MUST be registered in `appinfo/routes.php` BEFORE the Vue SPA catch-all route per ADR-003.

#### Scenario: API routes resolve before SPA catch-all

- **GIVEN** `GET /index.php/apps/procest/api/cases/{caseId}/emails` is requested
- **WHEN** Nextcloud dispatches the request
- **THEN** it MUST be handled by `CaseEmailController::listEmails()`, not the Vue SPA fallback

#### Scenario: Unauthenticated request is rejected

- **GIVEN** an unauthenticated HTTP request
- **WHEN** `POST /api/cases/{caseId}/emails` is called
- **THEN** the response MUST be `401 Unauthorized`

---

### REQ-CEI-008: The system SHALL provide `EmailComposer.vue`, `EmailThread.vue`, and `EmailTab.vue` in the case detail

**`EmailComposer.vue`** — Modal compose dialog:
- Recipient pre-filled from case contact; CC and BCC fields
- Subject pre-filled with `[{case.identifier}]` prefix
- Rich-text body editor
- Template selector dropdown (from `listTemplates()`)
- Case-document attachment picker with running size counter and validation against `email_max_attachment_size`
- Send confirmation step before dispatch
- Fully disabled (non-interactive) when case status `isFinal`

**`EmailThread.vue`** — Thread renderer:
- Messages in chronological ascending order
- Inbound left-aligned, outbound right-aligned
- Inline expand/collapse per message
- PDF download link; per-message Reply button opens `EmailComposer` pre-populated with `In-Reply-To`

**`EmailTab.vue`** — Sidebar tab in `CaseDetail.vue`:
- Groups messages by thread, most recent thread first
- Collapsible thread groups; message-count badge on the tab header

All components MUST import from `@conduction/nextcloud-vue`, not `@nextcloud/vue` directly (ADR-004). All user-visible strings via `t(appName, 'text')`.

#### Scenario: `EmailComposer` disabled for final-status case

- **GIVEN** a case with `isFinal: true`
- **WHEN** a handler opens the email tab
- **THEN** `EmailComposer` MUST be visually disabled and show a message explaining why

#### Scenario: Thread renders messages in chronological order with direction styling

- **GIVEN** a thread: outbound at 09:00, inbound at 14:00, outbound at 16:00
- **WHEN** the handler views `EmailThread.vue`
- **THEN** messages MUST appear oldest-first; outbound right, inbound left

---

### REQ-CEI-009: The system SHALL provide `EmailTemplateAdmin.vue` for per-case-type template CRUD and `UnlinkedQueue.vue` for manual linking

**`EmailTemplateAdmin.vue`** (in `CaseTypeDetail.vue`):
- Lists templates for the current case type
- Create and edit form with subject/body fields, variable sidebar grouped by source (case/contact/caseType)
- Live preview panel with unresolved variables highlighted in red

**`UnlinkedQueue.vue`** (standalone view):
- Lists `emailMessage` objects with `case: null`
- Per-row: sender, subject, received timestamp, body preview (≤ 200 chars)
- Search-and-link UI (search case by identifier or title), link on click
- Discard action with confirmation dialog (uses `NcDialog`, not `window.confirm()`)

#### Scenario: Unresolved variable highlighted in live preview

- **GIVEN** template body containing `{{case.nonExistentField}}`
- **WHEN** the admin views the live preview in `EmailTemplateAdmin.vue`
- **THEN** the placeholder MUST be rendered with a red background highlight and a warning listing unresolved names

#### Scenario: Manually linked email removed from queue

- **GIVEN** an unlinked email in `UnlinkedQueue.vue`
- **WHEN** the handler links it to case `ZAAK-2026-000142`
- **THEN** the email MUST be removed from the queue view and appear in that case's email tab

---

### REQ-CEI-010: The system SHALL provide admin email settings with SMTP/IMAP configuration and a test-connection action

`lib/Settings/EmailSettings.php` registers a Nextcloud admin settings section. `src/views/settings/EmailSettings.vue` renders:

- **SMTP**: host, port, encryption (none/starttls/ssl), username, password (masked), from-address
- **IMAP**: host, port, encryption, username, password (masked), folder (default: INBOX)
- **Transport selector**: standalone SMTP or Nextcloud Mail account picker
- **"Send test email" button** calling `POST /api/settings/email/test-smtp`; shows success or specific error

All password fields stored via `IAppConfig` with `setSensitive(true)`. Passwords MUST NOT appear in API responses in plaintext (return `***` placeholder after save). Layout follows ADR-004 admin pattern: `CnVersionInfoCard` first, then `CnSettingsSection` per feature group.

#### Scenario: Saved SMTP password not returned in plaintext

- **GIVEN** an admin saves SMTP credentials
- **WHEN** `GET /api/settings/email` is called
- **THEN** the response MUST contain `"smtp_password": "***"`, not the actual password

#### Scenario: Test-email button returns specific error on misconfiguration

- **GIVEN** an invalid SMTP host is configured
- **WHEN** the admin clicks "Send test email"
- **THEN** `POST /api/settings/email/test-smtp` MUST return a non-2xx response with an error describing the failure (hostname not found / connection refused / authentication failed)

---

### REQ-CEI-011: The system SHALL include seed data for all three new schemas in `procest_register.json`

Per ADR-001 seed data requirements, `procest_register.json` MUST include realistic seed objects using the `@self` envelope (3 `emailTemplate`, 3 `emailThread`, 4 `emailMessage` objects as defined in `design.md`). Dutch realistic values required. Seed loading MUST be idempotent — re-import with `force: false` MUST NOT create duplicates; objects matched by slug.

#### Scenario: Seed data loads idempotently

- **GIVEN** `openregister:load-register` has already run once
- **WHEN** it runs again with `force: false`
- **THEN** no duplicate `emailTemplate`, `emailMessage`, or `emailThread` objects MUST be created; slug-matched objects are skipped

#### Scenario: Seed templates appear in composer dropdown

- **GIVEN** the seed data is loaded and a case of the matching `caseType` is open
- **WHEN** a handler opens `EmailComposer.vue` and clicks the template selector
- **THEN** `Ontvangstbevestiging`, `Informatieverzoek`, and `Besluit` MUST appear in the dropdown
