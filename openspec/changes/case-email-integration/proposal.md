---
kind: code
depends_on:
  - case-management
  - case-types
  - admin-settings
chain: []
---

# Proposal: case-email-integration

**Status:** proposed
**Scope:** procest
**Owner:** Conduction BV — Procest team

## Why

Email remains the primary correspondence channel between municipalities and citizens, applicants, and partner organizations. Today, that correspondence sits in personal mailboxes outside the case system, breaking the audit trail and making it impossible to reconstruct a case after the fact. ZGW and Archiefwet require case correspondence to be archived as informatieobjecten.

This change closes the gap: every sent and received email is captured, classified, threaded, and surfaced in the case detail view and the activity timeline. Email becomes a first-class case interaction rather than a side channel invisible to the system.

## What changes

1. **Three new OpenRegister schemas** added to `procest_register.json`:
   - `emailTemplate` — reusable message templates per case type with `{{variable}}` placeholders and versioning
   - `emailMessage` — individual sent/received messages with RFC 2822 threading metadata and Docudesk PDF status
   - `emailThread` — conversation grouping object linking messages to a case

2. **Backend services and background jobs**:
   - `CaseEmailService` — outbound send, inbound processing, template variable resolution, RFC 2822 threading, Docudesk PDF orchestration
   - `EmailTemplateService` — template CRUD with version-on-edit (no overwrite)
   - `CaseEmailController` — authenticated REST API for compose, templates, and unlinked-queue endpoints
   - `InboundEmailJob` — `TimedJob` IMAP poller with auto-linking by case-number regex and `In-Reply-To` header
   - `EmailPdfRetryJob` — `TimedJob` retrying failed Docudesk conversions up to 3× with exponential backoff

3. **Frontend components** (new Vue 2 components):
   - `EmailComposer.vue` — modal compose dialog with template selector, attachment picker, CC/BCC
   - `EmailThread.vue` + `EmailTab.vue` — chronological thread view in case detail sidebar
   - `EmailTemplateAdmin.vue` — template CRUD per case type with variable sidebar and live preview
   - `UnlinkedQueue.vue` — manual linking of unmatched inbound emails
   - `EmailSettings.vue` — admin SMTP/IMAP configuration with test-connection

4. **Settings extension**: `email_*` config keys added to `SettingsService`; `EmailSettings.php` registers a new admin settings section.

5. **CaseDetail integration**: new "Email" sidebar tab and "Verstuur email" header action wired into existing `CaseDetail.vue`.

## Impact

- **Entities added:** 3 new OpenRegister schemas (`emailTemplate`, `emailMessage`, `emailThread`)
- **Entities modified:** none — `case` and `caseDocument` are consumed read-only
- **API routes added:** 10 new endpoints under `/index.php/apps/procest/api/`
- **Background jobs added:** 2 (`InboundEmailJob`, `EmailPdfRetryJob`)
- **Admin settings section added:** Email (SMTP / IMAP / transport / polling)
- **No breaking changes** to existing case-management, case-types, or admin-settings surfaces

## Out of scope

- Real-time push notifications from IMAP IDLE (polling only in this change)
- Calendar/iCalendar handling of meeting invitations
- Encrypted email (S/MIME, PGP)
- DMARC/SPF/DKIM authentication of inbound mail (delegated to mail server)

## Risks

| Risk | Mitigation |
|------|-----------|
| IMAP credentials exposure | Stored via `IAppConfig` with `setSensitive(true)`; never logged |
| Subject-regex abuse by attacker-controlled mail | Regex anchored; case lookup scoped to current organization (tenant isolation) |
| Docudesk failures block email reception | Conversion runs async; failures set `pdfStatus: failed` and trigger retry job |
| Memory exhaustion on large attachments | Messages > 5 MB deferred to background conversion; batch size configurable and capped at 50 |
