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

This change closes the gap: every relevant email is **linked to its case**, surfaced on the case detail page, and (for archival) captured as a `caseDocument` — without procest building a parallel mail client.

## Leaf-first decision (ADR-022)

Per **hydra ADR-022** ("integrate, don't build"), a feature that maps to an OpenRegister integration leaf MUST consume the leaf rather than build a parallel data model / UI / service. The email concept maps to the **`email` leaf** (NC Mail; id `email`, group `comms`, storage `link-table`). The leaf is **link-only** — NC Mail owns send/compose — and ships a sidebar tab + `CnEmailCard` widget that procest surfaces on the `case` detail page per **ADR-024** / **ADR-019**.

Therefore this change **consumes the `email` leaf** for all email **display, compose, link, and unlink**, and keeps only the genuinely case-specific pieces the leaf cannot do (per-zaaktype templating, PDF/`caseDocument` archival with retention, ZGW audit mapping). One requirement — unattended ingest of a **shared functional mailbox** with case-number auto-link — is a documented ADR-022 exception (see `openspec/architecture/adr-002-shared-mailbox-poller-exception.md`).

This **supersedes** an earlier draft of this change that specced a bespoke IMAP poller, SMTP composer, `emailMessage`/`emailThread` schemas, and a custom thread/compose UI. Those are removed here in favour of the leaf.

## What changes

1. **Consume the `email` leaf on the `case` detail page** (no new email-display code):
   - Surface the leaf's **email sidebar tab** and **`CnEmailCard` widget** on `case` objects via the app manifest (ADR-024) / integration registry (ADR-019).
   - Compose/reply happens in **NC Mail**; procest only links the resulting message to the case via the leaf endpoint `POST /api/objects/{register}/{schema}/{id}/email`.
   - Manual "link existing email" and unlink are the leaf's own affordances — no procest unlinked-queue UI.

2. **One app-local schema** (`emailTemplate`) — NOT email message storage:
   - `emailTemplate` — reusable per-zaaktype message templates with `{{variable}}` placeholders and versioning. NC Mail has no per-zaaktype templating bound to case data; this is a procest extension that prefills an NC Mail draft. No `emailMessage`/`emailThread` schema (the leaf link-table holds linked emails).

3. **Case-specific extensions of the leaf** (not replacements):
   - `EmailTemplateService` — template CRUD with version-on-edit (no overwrite) + default Dutch templates (`Ontvangstbevestiging`, `Informatieverzoek`, `Besluit`).
   - **Email → PDF → `caseDocument`** archival via the existing Docudesk integration, with retry, for Archiefwet/ZGW informatieobject compliance. The leaf does not archive; this runs when an email is linked.
   - ZGW audit-trail mapping of `email_linked` events.

4. **Documented ADR-022 exception — shared-mailbox poller**:
   - `InboundEmailJob` (`TimedJob`) polls a **functional/shared mailbox** (`zaken@gemeente.nl`) that has no per-user NC Mail account, auto-links by `[ZAAK-YYYY-NNNNNN]` subject regex, and records the link **through the leaf link-table**. Justified in `adr-002-shared-mailbox-poller-exception.md`. There is no bespoke message store; the poller writes via the leaf endpoint.

5. **Settings**: a small `EmailSettings` section for the **shared-mailbox IMAP** connection + transport choice (which NC Mail account / functional mailbox is the case-correspondence source). Per-user SMTP/IMAP is NOT configured here — NC Mail owns user accounts.

## Impact

- **Entities added:** 1 new OpenRegister schema (`emailTemplate`). No `emailMessage`/`emailThread` — linked emails live in the leaf link-table.
- **Entities consumed:** `case` (leaf tab/widget host), `caseDocument` (PDF archival).
- **Leaf consumed:** `email` (NC Mail) — tab + `CnEmailCard` widget + `POST .../email` link endpoint.
- **Background jobs added:** 2 — `InboundEmailJob` (shared-mailbox ingest, ADR-022 exception), `EmailPdfRetryJob` (archival retry).
- **Admin settings section added:** shared-mailbox IMAP + transport choice only.
- **Removed vs prior draft:** `emailMessage`/`emailThread` schemas, `CaseEmailService` send/transport, `EmailComposer.vue`, `EmailThread.vue`, `EmailTab.vue`, `UnlinkedQueue.vue`, SMTP send config, send/list/link/unlink controller endpoints — all replaced by the leaf.

## Out of scope

- Per-user mailbox compose/send (NC Mail / the email leaf own this)
- A bespoke email message data model, thread UI, or compose dialog (the leaf provides display + link)
- Real-time push notifications from IMAP IDLE (polling only)
- Calendar/iCalendar, encrypted email (S/MIME, PGP), DMARC/SPF/DKIM (delegated to mail server)

## Risks

| Risk | Mitigation |
|------|-----------|
| Shared-mailbox IMAP credentials exposure | Stored via `IAppConfig` with `setSensitive(true)`; never logged |
| Subject-regex abuse by attacker-controlled mail | Regex anchored; case lookup scoped to current organization (tenant isolation) |
| Docudesk failures block linking | Linking via the leaf is independent of PDF; archival runs async; failures set `pdfStatus: failed` and trigger retry job |
| Re-building what the leaf already does | Reviewer gate: no `emailMessage`/`emailThread` schema, no compose/thread Vue; display + compose + link come from the `email` leaf |
