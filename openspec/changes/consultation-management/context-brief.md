# Consultation Management Implementation

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Besluitvorming › Adviezen

**Rationale:** Consultatie-flow.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Why
Inter-departmental and external advisory consultations (adviesaanvragen) are currently exchanged via email, which destroys auditability, version control, and deadline enforcement. Awb articles 3:5-3:9 give consultation a legal status: the decision-maker must verify advice was produced diligently and respect reasonable response deadlines. Without structured consultations, municipalities cannot prove diligence on omgevingsvergunning, monumentenadvies, milieuadvies, or welstandstoets paths, and case workers have no central view of what is outstanding or blocking case completion. ArkCase and Flowable both model this concept as a sub-case linked to a parent — Procest needs the same primitive built on top of OpenRegister.

## What Changes
1. New `consultation`, `advisoryBody`, and `adviceResponse` schemas in `procest_register.json`.
2. `ConsultationService` for CRUD, lifecycle transitions, overdue detection, extension requests, and dependency enforcement.
3. `ConsultationController` REST API with routes for parent-case consultations, the consulted-party inbox, and external secure-link responses.
4. `ConsultationPanel.vue` (case-detail tab) and `ConsultationDashboard.vue` (department inbox) Vue components.
5. Parallel and sequential consultation patterns with mandatory-gate enforcement at the milestone level.
6. External advisory-body email path with secure response links for bodies without Nextcloud accounts.
7. Notifications, deadline monitoring, and activity-timeline integration via n8n.

## Impact
- Case detail view gains an "Adviezen" tab and a consultation summary badge.
- Dashboard gains a "Openstaande adviesaanvragen" widget and consultation performance KPIs.
- Mandatory consultations block case progression to decision milestones until completed.
- New cross-cutting integration with `milestone-tracking` for mandatory gates.

## Out of Scope
- Public participation / inspraak (citizen consultation on policy decisions).
- AI-generated advice drafting.
- Legal advice management with advocaat-client privilege.



## Design

# Consultation Management Design

## Architecture
A consultation is a first-class OpenRegister object linked to a parent zaak via a typed relation. It has an independent status lifecycle, its own deadline, and its own document attachments scoped to the consultation (not the entire parent case). Consultations can be parallel or sequential; mandatory consultations participate in milestone gates so that case progression is blocked until all required advice has been received. External advisory bodies that have no Nextcloud account interact via a per-consultation secure response link.

## Data Model
- **consultation** — `consultationNumber` (`ADV-{year}-{seq}`), `parentZaak`, `adviesInstantie`, `onderwerp`, `vraagstelling`, `uiterlijkeReactiedatum`, `prioriteit`, `status`, `assignee`, `mandatory`, `dependsOn` (array of consultation IDs), `secureToken` (for external bodies).
- **adviceResponse** — `consultation`, `advies` enum (`positief`, `positief_met_voorwaarden`, `negatief`, `niet_van_toepassing`), `toelichting`, `voorwaarden[]` (each with description + priority), `datum`, `bijlagen[]`.
- **advisoryBody** — `name`, `type` (`internal`/`external`), `defaultGroup`, `email`, `specializations[]`.

Status machine: `open -> ontvangen -> in_behandeling -> advies_uitgebracht -> afgesloten`, with `ingetrokken` as a side branch and coordinator-only backward transitions for corrections.

## Components
1. **ConsultationCreateDialog.vue** — invoked from `CaseDetail.vue`; selects advisory body, copies in case documents by reference, sets deadline default to 4 weeks.
2. **ConsultationPanel.vue** — "Adviezen" tab on case detail; renders consultation cards with progress, deadline, and advice outcomes; surfaces "2/4 adviezen ontvangen" summary.
3. **ConsultationDashboard.vue** — department-scoped inbox; filters by status, requester, deadline; supports `Oppakken` (claim) and reassignment.
4. **ConsultationResponseForm.vue** — used by consulted parties; structured response with conditional `voorwaarden` editor.
5. **ExternalConsultationResponsePage.vue** — public page accessed by secure token for external bodies; same fields minus internal navigation.

## Backend
- `ConsultationService` — CRUD, status machine, dependency check, mandatory-gate evaluator (queried by milestone-tracking).
- `ConsultationNotificationService` — emits events for create, acknowledge, deadline warnings (T-5 days), overdue (T+0), extension requests, response submitted.
- `ConsultationController` — REST under `/api/consultations`, plus `/api/public/consultations/{token}` for external bodies.
- `AdvisoryBodyService` — registry CRUD with specialization-weighted search.
- Document linkage uses OpenRegister's `relationsPlugin`; consulted-party access is scoped to consultation-linked documents only (enforced in `ConsultationController`).

## Integration
- **Milestone-tracking** — exposes `getBlockingConsultations(zaakId)`; mandatory consultations with status != `advies_uitgebracht` block decision milestones with the message listed in spec scenario.
- **Activity timeline** — `ActivityTimeline.vue` consumes consultation events from a shared event bus so create, acknowledge, response, and overdue events surface on the parent case.
- **n8n** — daily deadline-monitor workflow; email-fanout workflow for external bodies; bottleneck-detection workflow generating coordinator alerts when a body's overdue rate exceeds 20%.

## Risks & Mitigations
- Document-scope leakage — consulted parties must NOT see unrelated case documents; enforce at controller level by checking that requested attachments are linked to the consultation, not just the case.
- Token security for external bodies — tokens are 256-bit, single-purpose, expire on consultation closure, and all access is logged for BIO compliance.
- Dependency cycles — `dependsOn` is validated at write time to prevent cycles; UI surfaces the dependency graph.

## Standards
Awb 3:5-3:9, ZGW Zaken API, GEMMA adviesverzoek/adviesreactie, CMMN 1.1 CaseTask/Sentry, Common Ground "verwerken"/"notificeren", BIO access logging.



## Tasks

# Tasks

- [ ] TASK-CN-01: Add `consultation`, `adviceResponse`, and `advisoryBody` schemas to `procest_register.json` and register config keys in `SettingsService::SLUG_TO_CONFIG_KEY`.
- [ ] TASK-CN-02: Implement `ConsultationService` with CRUD, status machine, deadline/extension logic, dependency-cycle validation, and `getBlockingConsultations(zaakId)` for milestone gates.
- [ ] TASK-CN-03: Implement `AdvisoryBodyService` with specialization-weighted search and external-body email path including secure-token issuance.
- [ ] TASK-CN-04: Implement `ConsultationController` REST endpoints plus the public `/api/public/consultations/{token}` route with audited access logging (BIO).
- [ ] TASK-CN-05: Create `ConsultationCreateDialog.vue`, `ConsultationPanel.vue` (case-detail "Adviezen" tab), `ConsultationDashboard.vue` (department inbox), and `ConsultationResponseForm.vue`.
- [ ] TASK-CN-06: Create `ExternalConsultationResponsePage.vue` for token-based external responses; register route outside the authenticated app shell.
- [ ] TASK-CN-07: Add caseType admin UI to configure mandatory/optional consultation types per zaaktype with default body, default deadline, and dependencies.
- [ ] TASK-CN-08: Wire ActivityTimeline integration so consultation lifecycle events surface on the parent case, including the overdue warning event.
- [ ] TASK-CN-09: Add n8n deadline-monitor, email-fanout, and bottleneck-detection workflows; document webhook contracts.
- [ ] TASK-CN-10: Add Dutch + English i18n for all consultation UI and notification templates.
