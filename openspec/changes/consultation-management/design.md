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
