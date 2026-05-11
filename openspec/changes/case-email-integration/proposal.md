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
