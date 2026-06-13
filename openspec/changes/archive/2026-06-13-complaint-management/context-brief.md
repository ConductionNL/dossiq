# Complaint Management Implementation

## Why
Dutch municipalities are legally required to handle citizen complaints under Awb chapter 9, with mandated acknowledgment (5 working days), resolution (6 weeks plus an optional 4-week verdaging), the right to be heard (hoorgesprek), and a formal written disposition (oordeel). Currently Procest has no dedicated complaint infrastructure — complaints get logged as generic cases, losing channel-specific intake, deadline math, frequency analysis, and disposition tracking. This makes Awb compliance verifiable only by manual sampling and prevents detection of systemic complaint patterns (e.g., recurring complaints about a single department or employee).

## What Changes
1. New OpenRegister schemas: `complaint`, `hearing`, `complaintDisposition`, `complaintCategory`.
2. `ComplaintList.vue`, `ComplaintDetail.vue`, and `ComplaintDashboardWidget.vue` Vue components for handler workflow.
3. Awb deadline calculation helper (working-day math, verdaging, escalation) reusing `DeadlinePanel.vue`.
4. Intake flow for balie, telefoon, email (n8n), brief, website, socialmedia channels.
5. Bidirectional escalation link between complaints and zaken.
6. Frequency-analysis dashboard with category, department, and employee-threshold alerts.
7. Configurable complaint categories per tenant with default-handler routing.
8. Communication trail (acknowledgment letter via Docudesk, phone-call records, attachment matching by complaint number).

## Impact
- New schemas, new module, new controllers/services, three new Vue components, dashboard extensions.
- Reuses existing case infrastructure (status types, roles, document attachments, activity timeline) for the lifecycle.
- Integrates with n8n (intake + deadline monitoring), Docudesk (letter generation), Nextcloud Calendar (hearings), and Talk (video hearings).

## Out of Scope
- Bezwaarschriften (formal objections — separate workflow).
- Ombudsman case management and external oversight reporting.
- AI/NLP-based automatic classification.
- Citizen-facing complaint submission portal (handled separately).



## Design

# Complaint Management Design

## Architecture
Complaints are first-class entities stored in OpenRegister, distinct from `zaak`. The complaint lifecycle is enforced by a status machine layered on top of the existing status-record infrastructure; only the deadline math and a few specialized flows (hearing, disposition, escalation) require new code. n8n drives email intake, deadline monitoring, and notification fan-out.

## Data Model
Four new OpenRegister schemas in `procest_register.json`:
- **complaint** — core entity (`klachtnummer`, `klager`, `onderwerp`, `omschrijving`, `ontvangstdatum`, `ontvangstkanaal`, `categorie`, `betrokkenMedewerker`, `betrokkenAfdeling`, `status`, `behandelaar`, `prioriteit`, `ontvangstbevestigingDeadline`, `afhandelDeadline`, `verdagingMogelijk`, `geescaleerdeZaak`).
- **hearing** — linked to complaint (`datum`, `locatie`, `deelnemers`, `type`, `verslag`, `conclusie`, `datumAfgerond`, optional Talk room URL).
- **complaintDisposition** — `oordeel` enum, `toelichting`, `maatregelen[]`, `afsluitdatum`, `afsluitbrief`, optional approver.
- **complaintCategory** — `name`, `description`, `defaultHandler` (user or group), `slaOverride` (working-day count).

## Components
1. **ComplaintList.vue** — handler inbox; filters by status, category, handler, date range, priority; overdue items pinned in red.
2. **ComplaintDetail.vue** — header with `klachtnummer` and status, deadline panel (reuses `DeadlinePanel.vue`), hearing tab, disposition form, escalation action, communication tab, activity timeline.
3. **ComplaintDashboardWidget.vue** — "Mijn klachten" widget showing open count, overdue count, next 5 working-day deadlines.
4. **ComplaintAnalyticsDashboard.vue** — manager view with frequency bar charts (category, department, channel), trend lines, disposition pie, KPI cards against tenant targets.

## Backend
- `ComplaintService` — CRUD, status transitions, Awb deadline computation (working-day helper), verdaging logic, escalation linker.
- `HearingService` — scheduling, Calendar invitation via `OCP\Calendar\IManager`, Talk integration via `OCP\Talk\IBroker`.
- `DispositionService` — submission, optional coordinator approval gate, Docudesk template render for the response letter.
- `ComplaintAnalyticsService` — frequency aggregation, employee threshold alerts (anonymized notifications for HR), systemic-issue detection (>50% quarter-over-quarter increase).
- `ComplaintController` — REST routes under `/api/complaints` and `/api/complaints/{id}/...`.
- `SettingsService::SLUG_TO_CONFIG_KEY` extended with `complaint_register`, `complaint_schema`, `hearing_schema`, `disposition_schema`, `complaint_category_schema`.

## n8n Workflows
- **email-intake** — listens to klachten@gemeente.nl, creates draft complaint, attaches body and files.
- **deadline-monitor** — daily job sending warnings at T-3 working days (acknowledgment) and T-7 days (resolution); escalates overdue items to coordinator.
- **attachment-matcher** — links incoming emails whose subject contains a known `klachtnummer` back to the originating complaint.

## Risks & Mitigations
- Awb working-day math must respect Dutch public holidays — centralize in a `WorkingDayCalculator` helper with a holiday lookup; cover with unit tests.
- Privacy of `betrokkenMedewerker` data — HR alerts are anonymized in notifications and gated behind a separate ACL; raw data only visible to HR coordinators.
- Frequency reports risk re-identification with small populations — minimum threshold of 3 complaints per slice before showing employee-level data.

## Standards
Awb chapter 9, VNG Model Klachtenverordening, ISO 10002:2018, GEMMA klachtafhandeling, ZGW Zaken API (for ketenpartner exchange when needed).



## Tasks

# Tasks

- [ ] TASK-CM-01: Add `complaint`, `hearing`, `complaintDisposition`, and `complaintCategory` schemas to `procest_register.json` and register their config keys in `SettingsService::SLUG_TO_CONFIG_KEY`.
- [ ] TASK-CM-02: Implement `ComplaintService` with CRUD, status-machine transitions, Awb working-day deadline math, and verdaging logic; add unit tests for boundary dates and Dutch holidays.
- [ ] TASK-CM-03: Implement `HearingService` with Calendar invitations via `OCP\Calendar\IManager` and Talk room creation via `OCP\Talk\IBroker` for `videogesprek` hearings.
- [ ] TASK-CM-04: Implement `DispositionService` with optional coordinator approval gate and Docudesk-driven response-letter generation.
- [ ] TASK-CM-05: Implement `ComplaintAnalyticsService` with frequency aggregation, anonymized employee-threshold alerts (>=3 in 6 months), and systemic-issue detection (>50% QoQ increase).
- [ ] TASK-CM-06: Build `ComplaintController` REST endpoints for complaints, hearings, dispositions, escalation, and analytics.
- [ ] TASK-CM-07: Create `ComplaintList.vue`, `ComplaintDetail.vue` (reusing `DeadlinePanel.vue` and `ActivityTimeline.vue`), `ComplaintDashboardWidget.vue`, and `ComplaintAnalyticsDashboard.vue`.
- [ ] TASK-CM-08: Add the three n8n workflows (email-intake, deadline-monitor, attachment-matcher) and document the webhook endpoints they call.
- [ ] TASK-CM-09: Add tenant-admin UI for complaint categories (CRUD with default handler and SLA override) under `Settings > Klachtcategorieen`.
- [ ] TASK-CM-10: Add Dutch + English i18n strings for all complaint UI and notification templates.