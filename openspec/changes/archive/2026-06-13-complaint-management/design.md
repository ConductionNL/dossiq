# Complaint Management Design

status: pr-created

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
