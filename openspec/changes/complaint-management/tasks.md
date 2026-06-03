# Tasks: complaint-management

## Deduplication Check (ADR-012)

- [ ] **T0** — Verify no overlap with existing OR services and procest components before implementing.
  - Confirm `complaint`, `hearing`, `complaintDisposition`, `complaintCategory` schemas do not duplicate any existing procest register (case, caseType, hearingSession, decision, etc.).
  - Confirm `WorkingDayCalculator` does not duplicate logic already in a shared OR service or procest helper.
  - Confirm `ComplaintAnalyticsService` aggregation approach does not duplicate an existing `x-openregister-aggregations` block already declared in `procest_register.json`.
  - Confirm `ComplaintDashboardWidget.vue` does not duplicate an existing widget implementation.
  - Document findings in this task even if "no overlap found".
  - **files:** `openspec/changes/complaint-management/tasks.md` (this file, update with findings)
  - **spec_ref:** ADR-012, `design.md#reuse-analysis`

## Implementation Tasks

- [ ] **TASK-CM-01** — Add `complaint`, `hearing`, `complaintDisposition`, and `complaintCategory` schemas to `lib/Settings/procest_register.json` and register their config keys in `SettingsService::SLUG_TO_CONFIG_KEY`.
  - Declare schemas with schema.org annotations (`schema:Message`, `schema:Event`, `schema:AssessAction`, `schema:DefinedTerm`).
  - Include all fields defined in `design.md#data-model`.
  - Add seed data objects from `design.md#seed-data` under `components.objects[]` using `@self` envelopes.
  - Register 5 config keys: `complaint_register`, `complaint_schema`, `hearing_schema`, `disposition_schema`, `complaint_category_schema`.
  - **files:** `lib/Settings/procest_register.json`, `lib/Service/SettingsService.php`
  - **spec_ref:** `specs/complaint-management/spec.md#REQ-CM-001`, `design.md#data-model`, `design.md#seed-data`
  - **acceptance:** 4 new schemas present in register JSON; 5 config keys in `SLUG_TO_CONFIG_KEY`; seed objects load idempotently via `importFromApp()`

- [ ] **TASK-CM-02** — Implement `ComplaintService` with CRUD, status-machine transitions, Awb working-day deadline math via `WorkingDayCalculator`, and verdaging logic.
  - `WorkingDayCalculator::addWorkingDays(date, n)` with Dutch public holiday lookup (configurable holiday list).
  - On complaint create: compute `ontvangstbevestigingDeadline` (+5 working days) and `afhandelDeadline` (+30 working days), set `verdagingMogelijk: true`.
  - `applyVerdaging(complaintId)`: adds 4 calendar weeks, sets `verdagingMogelijk: false`, rejects if already applied.
  - Status transition enforcement (no skipping mandatory statuses unless hearing is waived).
  - Unit tests covering: regular deadline, Koningsdag boundary, Kerst–Nieuwjaar span, second verdaging rejection.
  - **files:** `lib/Service/ComplaintService.php`, `lib/Helper/WorkingDayCalculator.php`, `tests/unit/Service/ComplaintServiceTest.php`, `tests/unit/Helper/WorkingDayCalculatorTest.php`
  - **spec_ref:** `specs/complaint-management/spec.md#REQ-CM-001`, `specs/complaint-management/spec.md#REQ-CM-002`
  - **acceptance:** All unit tests pass; deadline for 2026-04-28 intake = `ontvangstbevestigingDeadline: 2026-05-05`, `afhandelDeadline: 2026-06-09`

- [ ] **TASK-CM-03** — Implement `HearingService` with Calendar invitations via `OCP\Calendar\IManager` and Talk room creation via `OCP\Talk\IBroker` for `videogesprek` hearings.
  - `scheduleHearing(complaintId, hearingData)`: creates `hearing` OR object, dispatches calendar invites to all `deelnemers`.
  - If `type === "videogesprek"`: call `OCP\Talk\IBroker::createConversation()`, store URL in `hearing.talkRoomUrl`, include in calendar invite.
  - `recordOutcome(hearingId, verslag, conclusie, aanwezigen)`: updates hearing, transitions complaint to `hoorgesprek_afgerond`.
  - If Calendar or Talk integration fails: log warning, continue without invite, set `hearing.calendarInviteFailed: true`.
  - **files:** `lib/Service/HearingService.php`, `tests/unit/Service/HearingServiceTest.php`
  - **spec_ref:** `specs/complaint-management/spec.md#REQ-CM-003`
  - **acceptance:** Hearing created with correct fields; Talk URL stored for videogesprek type; calendar invite dispatched (mocked in tests)

- [ ] **TASK-CM-04** — Implement `DispositionService` with optional coordinator approval gate and Docudesk-driven response-letter generation.
  - `submitDisposition(complaintId, dispositionData)`: creates `complaintDisposition` OR object.
  - If tenant setting `klachten_goedkeuring_vereist: true`: set complaint status to `wacht_op_goedkeuring`, send task to `klachten-coordinator` group.
  - `approveDisposition(dispositionId, coordinatorUid)`: sets `goedgekeurdDoor`, transitions complaint to `afgehandeld`.
  - `generateAfsluitbrief(dispositionId)`: calls Docudesk with `afsluitbrief` template, stores resulting document UUID in `complaintDisposition.afsluitbrief`, links document to complaint via OR files.
  - `generateOntvangstbevestiging(complaintId)`: calls Docudesk with `ontvangstbevestiging` template, transitions complaint to `ontvangst_bevestigd`.
  - **files:** `lib/Service/DispositionService.php`, `tests/unit/Service/DispositionServiceTest.php`
  - **spec_ref:** `specs/complaint-management/spec.md#REQ-CM-005`, `specs/complaint-management/spec.md#REQ-CM-009`
  - **acceptance:** Disposition created; approval gate toggleable via settings; Docudesk integration produces linked document

- [ ] **TASK-CM-05** — Implement `ComplaintAnalyticsService` with frequency aggregation, anonymized employee-threshold alerts, and systemic-issue detection.
  - `getFrequencyByCategory(period)`, `getFrequencyByDepartment(period)`, `getFrequencyByChannel(period)` — aggregate over OR `complaint` objects.
  - `checkEmployeeThreshold()`: find `betrokkenMedewerker` UIDs with ≥3 complaints in rolling 6-month window; send anonymized Nextcloud notification to `hr-coordinator` role (notification text MUST NOT include employee name/UID).
  - `detectSystemicIssues()`: compare current quarter vs previous quarter per category; generate "Systeemmelding" banner data when >50% increase.
  - Minimum-population guard: employee-level data suppressed if slice < 3 complaints.
  - **files:** `lib/Service/ComplaintAnalyticsService.php`, `tests/unit/Service/ComplaintAnalyticsServiceTest.php`
  - **spec_ref:** `specs/complaint-management/spec.md#REQ-CM-006`
  - **acceptance:** Threshold alert fires at exactly 3 complaints; notification text contains no PII; systemic detection fires at >50% QoQ increase

- [ ] **TASK-CM-06** — Build `ComplaintController` REST endpoints for complaints, hearings, dispositions, escalation, and analytics.
  - Route prefix: `/index.php/apps/procest/api/complaints` per ADR-002.
  - Endpoints: `GET /complaints`, `POST /complaints`, `GET /complaints/{id}`, `PUT /complaints/{id}`, `POST /complaints/{id}/hearings`, `PUT /complaints/{id}/hearings/{hid}`, `POST /complaints/{id}/disposition`, `POST /complaints/{id}/escalate`, `POST /complaints/{id}/verdaging`, `GET /complaints/analytics`.
  - Controller methods: thin (<10 lines); delegate to `ComplaintService`, `HearingService`, `DispositionService`, `ComplaintAnalyticsService`.
  - Add `@spec openspec/changes/complaint-management/tasks.md#TASK-CM-06` PHPDoc on all public methods.
  - **files:** `lib/Controller/ComplaintController.php`, `appinfo/routes.php`
  - **spec_ref:** `specs/complaint-management/spec.md` (all REQ-CM-*), ADR-002, ADR-003
  - **acceptance:** All REST routes registered; controller methods ≤10 lines; integration tests pass for create, update, and analytics endpoints

- [ ] **TASK-CM-07** — Create `ComplaintList.vue`, `ComplaintDetail.vue`, `ComplaintDashboardWidget.vue`, and `ComplaintAnalyticsDashboard.vue`.
  - `ComplaintList.vue`: `CnIndexPage` + `useListView(complaintStore)`. Columns per REQ-CM-008. Overdue items: red `CnStatusBadge`. Filters: status, categorie, behandelaar, date range, prioriteit.
  - `ComplaintDetail.vue`: `CnDetailPage` with 7 tabs (Klacht, Deadlines, Hoorgesprek, Afsluiting, Escalatie, Communicatie, Bijlagen). Reuse `DeadlinePanel.vue` in Deadlines tab; `ActivityTimeline.vue` in Communicatie tab; `CnObjectSidebar` for Bijlagen + Audit.
  - `ComplaintDashboardWidget.vue`: `CnWidgetWrapper` showing open count, overdue count, next 5 deadlines. Click → complaint list.
  - `ComplaintAnalyticsDashboard.vue`: `CnDashboardPage` with `CnStatsBlock` KPI cards, `CnChartWidget` bar charts (category, department, channel), trend line, disposition pie, systeemmelding banner.
  - All strings via `t(appName, 'key')`. No hardcoded colors — Nextcloud CSS variables only.
  - **files:** `src/views/complaints/ComplaintList.vue`, `src/views/complaints/ComplaintDetail.vue`, `src/views/dashboard/ComplaintDashboardWidget.vue`, `src/views/analytics/ComplaintAnalyticsDashboard.vue`, `src/store/modules/complaints.js`
  - **spec_ref:** `specs/complaint-management/spec.md#REQ-CM-008`, ADR-004
  - **acceptance:** List shows correct columns; overdue items highlighted; detail tabs all functional; widget renders on dashboard; analytics charts load from API

- [ ] **TASK-CM-08** — Add the three n8n workflows (email-intake, deadline-monitor, attachment-matcher) and document the webhook endpoints they call.
  - **email-intake**: trigger on new message at `klachten@gemeente.nl`; POST to `/api/complaints` with `ontvangstkanaal: "email"`, `omschrijving` from body, `klager.email` from sender; notify handler group.
  - **deadline-monitor**: daily cron; GET overdue complaints via `/api/complaints?overdue=true`; POST `/api/complaints/{id}/notify` for T-3 (acknowledgment) and T-7 (resolution) warnings; POST `/api/complaints/{id}/escalate-coordinator` for past-deadline items.
  - **attachment-matcher**: trigger on new message with subject containing `KL-\d{4}-\d{4}`; extract klachtnummer; POST `/api/complaints/{id}/attachments` with email attachments; notify `behandelaar`.
  - Document all webhook endpoints called by n8n in a `docs/n8n-webhooks.md` file.
  - **files:** `n8n/workflows/complaint-email-intake.json`, `n8n/workflows/complaint-deadline-monitor.json`, `n8n/workflows/complaint-attachment-matcher.json`, `docs/n8n-webhooks.md`
  - **spec_ref:** `specs/complaint-management/spec.md#REQ-CM-001`, `specs/complaint-management/spec.md#REQ-CM-002`, `specs/complaint-management/spec.md#REQ-CM-009`
  - **acceptance:** Email intake creates draft complaint; deadline monitor sends notifications at correct thresholds; attachment matcher links files to correct complaint

- [ ] **TASK-CM-09** — Add tenant-admin UI for complaint categories (CRUD with default handler and SLA override) under `Settings > Klachtcategorieen`.
  - Add a `CnSettingsSection` for "Klachtcategorieen" in the admin settings panel (per ADR-004 Settings pattern — modal, not a `/settings` route).
  - `CnIndexPage` + `CnFormDialog` (schema-driven from `complaintCategory` schema) for category CRUD.
  - Deactivation toggle (`actief` field) with confirmation dialog.
  - **files:** `src/views/settings/ComplaintCategorySettings.vue`, `src/store/modules/complaintCategories.js`
  - **spec_ref:** `specs/complaint-management/spec.md#REQ-CM-007`
  - **acceptance:** Admin can create, edit, deactivate categories; deactivated categories absent from intake dropdown; existing complaints unaffected

- [ ] **TASK-CM-10** — Add Dutch and English i18n strings for all complaint UI and notification templates.
  - Add English source strings for all `t(appName, 'key')` calls in complaint Vue components to `l10n/` source.
  - Add Dutch translations to `l10n/nl.json` for all complaint-related keys: column headers, status labels, action buttons, error messages, notification templates.
  - Verify no user-visible hardcoded strings remain in complaint Vue templates.
  - **files:** `l10n/nl.json`, `l10n/en.json` (or equivalent source file)
  - **spec_ref:** `specs/complaint-management/spec.md#REQ-CM-010`, ADR-007
  - **acceptance:** Dutch and English strings present for all complaint UI keys; grep for hardcoded Dutch/English in `<template>` blocks returns zero matches

## Reviewer Verification (pre-merge)

- [ ] **T11** — Reviewer confirms no `lib/Db/` Mapper classes name `complaint_`, `klacht_`, or `klachten_`. All complaint data flows through the OR object API.
  - **acceptance:** `grep -r "complaint_mapper\|klacht_mapper" lib/Db/` returns zero results

- [ ] **T12** — Reviewer confirms `WorkingDayCalculator` is unit-tested with boundary dates: Koningsdag (27 April), Kerst–Nieuwjaar span, Pasen (variable), and verdaging double-application rejection.
  - **acceptance:** `WorkingDayCalculatorTest.php` has ≥5 boundary test cases; all pass

- [ ] **T13** — Reviewer confirms `betrokkenMedewerker` field is not returned in analytics API responses to users without `klachten-coordinator` role.
  - **acceptance:** API integration test with `handler` role returns 403 or masked value for `betrokkenMedewerker`

- [ ] **T14** — Reviewer confirms all Vue components use only Nextcloud CSS variables (no hardcoded hex colors) and all strings use `t()`.
  - **acceptance:** `grep -r "#[0-9a-fA-F]\{3,6\}" src/views/complaints/` returns zero matches; `grep -rn "'[A-Z][a-z]" src/views/complaints/` (for hardcoded capitalized strings) reviewed

- [ ] **T15** — Reviewer confirms every public method in `ComplaintController.php`, `ComplaintService.php`, `HearingService.php`, `DispositionService.php`, `ComplaintAnalyticsService.php` carries `@spec` PHPDoc tag per ADR-003.
  - **acceptance:** `grep -c "@spec" lib/Controller/ComplaintController.php` ≥ number of public methods
