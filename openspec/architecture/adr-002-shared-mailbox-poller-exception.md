# ADR-002: Shared-mailbox IMAP poller is an ADR-022 documented exception

- **Status:** Accepted
- **Date:** 2026-05-25
- **Deciders:** Procest product + architecture
- **Scope:** procest (zaaksysteem) — case correspondence intake
- **References:** [hydra ADR-022 — Apps consume OpenRegister abstractions](../../../hydra/openspec/architecture/adr-022-apps-consume-or-abstractions.md) (§ Exceptions), [hydra ADR-019 — Integration registry](../../../hydra/openspec/architecture/adr-019-integration-registry.md), [openregister `integration-email` leaf](../../../openregister/openspec/changes/integration-email/specs/integration-email/spec.md)

## Context

ADR-022 mandates "integrate, don't build": where OpenRegister provides an
abstraction that fits, the app MUST consume it rather than build a parallel
mechanism. The `email` integration leaf (NC Mail, id `email`, group `comms`,
storage `link-table`) is the canonical abstraction for surfacing email on an
OR object. The `case-email-integration` change consumes that leaf for all
**display, compose, link, and unlink** of correspondence on the `case` detail
page (sidebar tab + `CnEmailCard` widget per ADR-024).

One requirement does not fit the leaf. Dutch municipalities run **functional
mailboxes** (`zaken@gemeente.nl`, `vergunningen@gemeente.nl`) that receive
citizen correspondence with no human inbox owner. Procest must **ingest** those
messages unattended and **auto-link** them to a case by the `[ZAAK-YYYY-NNNNNN]`
subject tag, so correspondence reaches the case file even when no case worker
is watching the mailbox.

The `email` leaf cannot satisfy this:

- The leaf is **link-only** — "Mail owns send/compose" and the tab provides
  "Link existing email", a per-user manual affordance. There is no server-side
  ingest path.
- `EmailProvider::requiresPermission()` returns `null`; access "inherits from
  object RBAC + Mail app access (user sees only emails in accounts they
  control)". A shared functional mailbox has no controlling user, so there is
  nothing for the leaf to enumerate on a background job.
- The leaf has no auto-link-by-subject-regex contract; linking is a deliberate
  user action.

## Decision

Per ADR-022 § Exceptions clause 1 (**fundamentally different domain
requirements** — unattended ingest of an owner-less functional mailbox), procest
ships a **server-side IMAP poller** (`InboundEmailJob`, a `TimedJob`) **scoped
strictly to**:

1. Connecting to the configured **shared/functional** IMAP mailbox.
2. Auto-linking each message to a case by `\[([A-Z]+-\d{4}-\d{6})\]` subject
   regex (subject header only, scoped to the current organization).
3. Recording the link **through the email leaf's link-table** via
   `POST /api/objects/{register}/{schema}/{id}/email` — NOT a procest-local
   `emailMessage` table.

Everything the leaf can do, procest consumes from the leaf and does NOT rebuild:
per-user compose/send (NC Mail), the sidebar tab, the `CnEmailCard` widget,
manual "link existing email", unlink. There is **no** procest `EmailComposer`,
`EmailThread`, `emailMessage`/`emailThread` schema, SMTP transport, or unlinked
queue.

## Boundaries of the exception

The exception covers **only** unattended shared-mailbox ingest + auto-link.
It explicitly does NOT license:

- A parallel `emailMessage`/`emailThread` data model (use the leaf link-table).
- Bespoke compose/send (NC Mail owns it).
- A bespoke display tab/widget (use the leaf's tab + `CnEmailCard`).
- App-local RBAC or audit for email (OR audit on the linked object).

## Migration / sunset

If OpenRegister later adds a shared-mailbox ingest contract to the `email` leaf
(an unattended functional-mailbox provider with subject-based auto-link), procest
MUST migrate `InboundEmailJob` onto it and retire the app-local poller. Tracked
as a follow-up against the openregister integration-registry umbrella.

## Consequences

- **Positive:** one narrow, justified piece of app-local code; all email
  display/compose/link consumed from the leaf; no duplicate data model.
- **Negative:** procest carries IMAP connection + credential config for the
  functional mailbox until OR offers the contract.
