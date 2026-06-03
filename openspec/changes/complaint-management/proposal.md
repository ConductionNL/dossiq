---
kind: code
depends_on:
  - case-management
  - case-types
  - openregister
chain: []
---

# Proposal: complaint-management

**Status:** proposed
**Scope:** procest
**Owner:** Conduction BV — Procest team

## Why

Dutch municipalities are legally required to handle citizen complaints under Awb chapter 9, with mandated acknowledgment (5 working days), resolution (6 weeks plus an optional 4-week verdaging), the right to be heard (hoorgesprek), and a formal written disposition (oordeel). Currently Procest has no dedicated complaint infrastructure — complaints get logged as generic cases, losing channel-specific intake, deadline math, frequency analysis, and disposition tracking. This makes Awb compliance verifiable only by manual sampling and prevents detection of systemic complaint patterns (e.g., recurring complaints about a single department or employee).

## What Changes

1. **Four new OpenRegister schemas** in `procest_register.json`: `complaint`, `hearing`, `complaintDisposition`, `complaintCategory` — with config keys registered in `SettingsService::SLUG_TO_CONFIG_KEY`.
2. **Vue components**: `ComplaintList.vue`, `ComplaintDetail.vue` (reusing `DeadlinePanel.vue` and `ActivityTimeline.vue`), `ComplaintDashboardWidget.vue`, and `ComplaintAnalyticsDashboard.vue`.
3. **Awb deadline calculation**: `WorkingDayCalculator` helper for working-day math, verdaging, and escalation, with Dutch public holiday lookup.
4. **Multi-channel intake**: balie, telefoon, email (via n8n), brief, website, socialmedia.
5. **Bidirectional escalation link**: between complaints and zaken (`geescaleerdeZaak` / `bronKlacht`).
6. **Frequency-analysis dashboard**: category, department, and employee-threshold alerts with anonymized HR notifications.
7. **Configurable complaint categories**: per-tenant CRUD with default-handler routing and SLA override.
8. **Communication trail**: acknowledgment letter via Docudesk, phone-call records, attachment matching by complaint number via n8n.
9. **Three n8n workflows**: email-intake, deadline-monitor, attachment-matcher.
10. **Dutch + English i18n** for all complaint UI and notification templates.

## Impact

- **New schemas** (4): `complaint`, `hearing`, `complaintDisposition`, `complaintCategory` in `procest_register.json`.
- **New services** (4): `ComplaintService`, `HearingService`, `DispositionService`, `ComplaintAnalyticsService`.
- **New controller** (1): `ComplaintController` — REST routes under `/index.php/apps/procest/api/complaints`.
- **New Vue components** (4): `ComplaintList.vue`, `ComplaintDetail.vue`, `ComplaintDashboardWidget.vue`, `ComplaintAnalyticsDashboard.vue`.
- **New n8n workflows** (3): email-intake, deadline-monitor, attachment-matcher.
- **Settings extension**: `SettingsService::SLUG_TO_CONFIG_KEY` gains 5 new keys.
- **Reuses** existing case infrastructure (statusTypes, roles, document attachments, `ActivityTimeline.vue`, `DeadlinePanel.vue`) for lifecycle events.
- **Integrations**: n8n (intake + deadline monitoring), Docudesk (letter generation), Nextcloud Calendar (`OCP\Calendar\IManager`), Nextcloud Talk (`OCP\Talk\IBroker`).

## Out of Scope

- Bezwaarschriften (formal objections) — separate workflow with different legal requirements.
- Ombudsman case management and external oversight reporting.
- AI/NLP-based automatic classification.
- Citizen-facing complaint submission portal (handled in a separate change).
- Belgian or other non-Dutch complaint procedure variants.

## Reviewer Gates

- ADR-001 data layer: all complaint data flows through OpenRegister objects — no custom `lib/Db/` mappers for complaint domain data.
- ADR-003 backend: Controller → Service → (OR ObjectService) — no direct mapper calls from controllers.
- ADR-004 frontend: Vue 2 + Pinia, `createObjectStore`, Nextcloud CSS variables, all strings via `t()`.
- ADR-011 schema standards: new schemas carry schema.org annotation (`schema:Message` for complaint, `schema:Event` for hearing).
- ADR-012 deduplication: design doc MUST include Reuse Analysis section confirming no duplication with existing OR services.
- ADR-001 seed data: design.md MUST include seed data (3-5 objects per schema) using realistic Dutch values.
- Privacy gate: `betrokkenMedewerker` field in frequency reports is only readable by `klachten-coordinator` role; HR alerts are anonymized in notification text.
