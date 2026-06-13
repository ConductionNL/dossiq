# Citizen-facing zaakportaal "Mijn gemeente"

## Why

Dutch citizens and businesses conducting matters with municipalities currently lack direct, real-time access to case information. They receive status updates via generic Berichtenbox messages or telephone, leading to repeated "where is my application?" calls, uncertainty about deadlines, and missed self-service opportunities. Municipalities also suffer inefficient call-center volumes over routine status inquiries.

Mijn gemeente closes this gap: a dedicated, DigiD- and eHerkenning-authenticated citizen portal offering self-service access to active cases, transparent status timelines, secure document download, direct messaging with case handlers, and self-initiated complaint/objection filing. All data flows read-only or narrowly-scoped from the internal Procest system, ensuring no shadow administration and preserving data integrity.

## What Changes

1. **New Nextcloud app:** `zaakportaal` (separate codebase, own subdomainmijn.gemeente.nl on production).
2. **Authentication layer:** OpenConnector integration for DigiD OIDC, eHerkenning SAML, DigiD Machtigen, eHerkenning Ketenmachtiging; short-lived, IP+user-agent-bound sessions.
3. **Citizen-facing UI** (Vue + NL Design System): Case overview list, case detail page with status timeline, document viewer, messaging widget, complaint/bezwaar intake forms, notification preference manager.
4. **Dedicated API layer** (REST): Read-only retrieval of cases, documents, statuses, and decision metadata; write-only for messages, complaints, and objections via restricted endpoints.
5. **External integrations:** Procest (case data), OpenRegister (documents + audit logging), Docudesk (notification templates, intake letters), Berichtenbox (official notifications), n8n (message queueing, notification delivery).
6. **Data minimization:** No case duplication in portal database; session tokens are short-lived (15 min) and environment-bound; all audit trails logged to OpenRegister.

## Impact

- New app module: `zaakportaal` (~10-15 Vue components, ~5-8 API endpoints, OpenConnector service binding).
- Reuses existing infrastructure: Procest case/decision/document schemas, OpenRegister audit trails, Docudesk templates, n8n notification workflows.
- Single point of change: If case metadata changes in Procest, citizen sees it immediately on next page load (no sync latency).
- Privacy-first by default: Citizens see only documents and case fields explicitly marked as citizen-facing in Procest.

## Out of Scope

- **Payment processing** (iDEAL for leges/dwangsommen) — roadmap for Phase 2.
- **Appointment booking** (balieafspraken via Google Calendar / Calendly) — roadmap for Phase 2.
- **E-forms with conditional logic** — limited scope for Phase 1 (simple intake forms for complaint/bezwaar/subsidy); complex multi-step forms deferred.
- **Multi-channel messaging** (WhatsApp, Signal, Teams) — openconnector adapters architected but not implemented; roadmap Phase 3.
- **Administrative portal features** (reporting dashboards, staff-side complaint management) — belongs in Procest app, not zaakportaal.
- **Bezwaarschriftencommissie hearing management** — belongs in Procest; zaakportaal surfaces the outcome only.
