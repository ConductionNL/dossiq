# Tasks

- [x] TASK-CM-01: Add `complaint`, `hearing`, `complaintDisposition`, and `complaintCategory` schemas to `procest_register.json` and register their config keys in `SettingsService::SLUG_TO_CONFIG_KEY`.
- [x] TASK-CM-02: Implement `ComplaintService` with CRUD, status-machine transitions, Awb working-day deadline math, and verdaging logic; add unit tests for boundary dates and Dutch holidays.
- [x] TASK-CM-03: Implement `HearingService` with Calendar invitations via `OCP\Calendar\IManager` and Talk room creation via `OCP\Talk\IBroker` for `videogesprek` hearings.
- [x] TASK-CM-04: Implement `DispositionService` with optional coordinator approval gate and Docudesk-driven response-letter generation.
- [x] TASK-CM-05: Implement `ComplaintAnalyticsService` with frequency aggregation, anonymized employee-threshold alerts (>=3 in 6 months), and systemic-issue detection (>50% QoQ increase).
- [x] TASK-CM-06: Build `ComplaintController` REST endpoints for complaints, hearings, dispositions, escalation, and analytics.
- [ ] TASK-CM-07: Create `ComplaintList.vue`, `ComplaintDetail.vue` (reusing `DeadlinePanel.vue` and `ActivityTimeline.vue`), `ComplaintDashboardWidget.vue`, and `ComplaintAnalyticsDashboard.vue`.
- [x] TASK-CM-08: Add the three n8n workflows (email-intake, deadline-monitor, attachment-matcher) and document the webhook endpoints they call — `n8n/complaint-email-intake.json`, `n8n/complaint-deadline-monitor.json`, `n8n/complaint-attachment-matcher.json`, plus `docs/n8n-complaint-workflows.md` covering trigger schedules, request/response shapes, and credentials.
- [ ] TASK-CM-09: Add tenant-admin UI for complaint categories (CRUD with default handler and SLA override) under `Settings > Klachtcategorieen`.
- [ ] TASK-CM-10: Add Dutch + English i18n strings for all complaint UI and notification templates.
