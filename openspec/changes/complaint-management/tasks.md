# Tasks

> **Build status (hydra audit).** Backend (TASK-CM-01..06) is shipped:
> see `lib/Service/ComplaintService.php`,
> `lib/Service/HearingService.php`, `lib/Service/DispositionService.php`,
> `lib/Service/ComplaintAnalyticsService.php`, and
> `lib/Controller/ComplaintController.php`. The frontend list/detail
> views, n8n workflows, tenant-admin category UI, and full i18n bundle
> (TASK-CM-07..10) are **genuinely open** — only
> `src/views/complaints/components/ComplaintCreateDialog.vue` exists today.
> Left as `[ ]` so the next builder picks them up.

- [x] TASK-CM-01: Add `complaint`, `hearing`, `complaintDisposition`, and `complaintCategory` schemas to `procest_register.json` and register their config keys in `SettingsService::SLUG_TO_CONFIG_KEY`.
- [x] TASK-CM-02: Implement `ComplaintService` with CRUD, status-machine transitions, Awb working-day deadline math, and verdaging logic; add unit tests for boundary dates and Dutch holidays.
- [x] TASK-CM-03: Implement `HearingService` with Calendar invitations via `OCP\Calendar\IManager` and Talk room creation via `OCP\Talk\IBroker` for `videogesprek` hearings.
- [x] TASK-CM-04: Implement `DispositionService` with optional coordinator approval gate and Docudesk-driven response-letter generation.
- [x] TASK-CM-05: Implement `ComplaintAnalyticsService` with frequency aggregation, anonymized employee-threshold alerts (>=3 in 6 months), and systemic-issue detection (>50% QoQ increase).
- [x] TASK-CM-06: Build `ComplaintController` REST endpoints for complaints, hearings, dispositions, escalation, and analytics.
- [ ] TASK-CM-07: Create `ComplaintList.vue`, `ComplaintDetail.vue` (reusing `DeadlinePanel.vue` and `ActivityTimeline.vue`), `ComplaintDashboardWidget.vue`, and `ComplaintAnalyticsDashboard.vue`.
- [ ] TASK-CM-08: Add the three n8n workflows (email-intake, deadline-monitor, attachment-matcher) and document the webhook endpoints they call.
- [ ] TASK-CM-09: Add tenant-admin UI for complaint categories (CRUD with default handler and SLA override) under `Settings > Klachtcategorieen`.
- [ ] TASK-CM-10: Add Dutch + English i18n strings for all complaint UI and notification templates.
