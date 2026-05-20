---
kind: code
depends_on: []
chain: []
---

# Proposal: consultation-management

**Status:** proposed
**Scope:** procest
**Owner:** Conduction BV — Procest team

## Why

Inter-departmental and external advisory consultations (adviesaanvragen) are currently exchanged via email, which destroys auditability, version control, and deadline enforcement. Awb articles 3:5-3:9 give consultation a legal status: the decision-maker must verify advice was produced diligently and respect reasonable response deadlines. Without structured consultations, municipalities cannot prove diligence on omgevingsvergunning, monumentenadvies, milieuadvies, or welstandstoets paths, and case workers have no central view of what is outstanding or blocking case completion. ArkCase and Flowable both model this concept as a sub-case linked to a parent — Procest needs the same primitive built on top of OpenRegister.

The existing `adviesAanvraag` schema in `procest_register.json` covers only the minimal advice-request data shape (case reference, adviseur, deadline). It does not model advisory-body registries, structured responses with condition lists, parallel/sequential orchestration, mandatory-gate enforcement, or external secure-link access. This change adds three new schemas (`consultation`, `adviceResponse`, `advisoryBody`) and the full service/controller/UI layer on top.

## What Changes

1. New `consultation`, `adviceResponse`, and `advisoryBody` schemas in `procest_register.json`.
2. `ConsultationService` for CRUD, lifecycle transitions, overdue detection, extension requests, and dependency enforcement.
3. `ConsultationController` REST API with routes for parent-case consultations, the consulted-party inbox, and external secure-link responses.
4. `ConsultationPanel.vue` (case-detail "Adviezen" tab) and `ConsultationDashboard.vue` (department inbox) Vue components.
5. Parallel and sequential consultation patterns with mandatory-gate enforcement at the milestone level.
6. External advisory-body email path with secure response links for bodies without Nextcloud accounts.
7. Notifications, deadline monitoring, and activity-timeline integration via n8n.

## Impact

- Case detail view gains an "Adviezen" tab and a consultation summary badge.
- Dashboard gains an "Openstaande adviesaanvragen" widget and consultation performance KPIs.
- Mandatory consultations block case progression to decision milestones until completed.
- New cross-cutting integration with `milestone-tracking` for mandatory gates.
- `procest_register.json` grows by 3 schemas; existing schemas (`case`, `adviesAanvraag`) are unchanged.

## Out of Scope

- Public participation / inspraak (citizen consultation on policy decisions).
- AI-generated advice drafting.
- Legal advice management with advocaat-client privilege.
- Migrating existing email-based advice records — import tooling is a separate operational task.

## Reviewer Gates

- **ADR-001 data layer**: all three new entities stored as OpenRegister objects; no custom DB tables. Reviewer checks for absence of `lib/Db/Consultation*Mapper.php`.
- **ADR-002 API**: `ConsultationController` routes follow `/api/consultations` pattern; public endpoint annotated `#[PublicPage]` + `#[NoCSRFRequired]`; per-object IDOR check in every mutation.
- **ADR-003 backend**: Controller → Service pattern; no business logic in controllers; `@spec` PHPDoc tags on every public method.
- **ADR-004 frontend**: all strings via `t(appName, …)`; Pinia store via `createObjectStore`; no raw `fetch()` for mutations.
- **ADR-005 security**: 256-bit secure token; token expires on consultation closure; external access logged for BIO compliance; no PII in log output.
- **ADR-011 deduplication**: `adviesAanvraag` in ADR-000 is retained — `consultation` adds richer lifecycle, structured response, advisory-body registry, and mandatory-gate logic. The justification for new schemas is documented in `design.md` Reuse Analysis.
- **ADR-012 deduplication check**: `design.md` contains a Reuse Analysis table confirming no overlap with existing OpenRegister services.
