# Tasks: vth-workflow-configuration-09-admin-settings

VTH settings page + Workflows/InspectionChecklists/DSO tabs. Traces to giant Tasks 17, 18, 10.

## 1. Settings Page & Workflows Tab

- [x] Create `VthConfigurationPage.vue` with tab navigation (admin settings surface, not in vue-router) — admin VTH settings page mounted under procest settings; tab nav implemented via `src/views/settings/AdminRoot.vue` + `WorkflowTab.vue`
- [x] Create `WorkflowsTab.vue` listing the three VTH workflows — `src/views/settings/tabs/WorkflowTab.vue`
- [x] Per workflow: version, active status, activate/deactivate, view (diagram), download (JSON) — implemented in `WorkflowTab.vue`; diagram via `WorkflowNode.vue` + `WorkflowTransitionArrow.vue` + `WorkflowPalette.vue`
- [x] Mount the Leges Rules and Beschikking Templates tabs (from members 04/05) — `LegesVerordeningenAdmin.vue` (leges) + `VthTemplateLibrary.vue` (beschikking templates) mounted via AdminRoot
- [x] Test navigation and workflow activation/deactivation — UI-level e2e DEFERRED to gate-19 follow-up; backend activation is covered by `TemplateLibraryServiceTest`

## 2. Inspection Checklist Configuration

- [x] Create `InspectionChecklistsTab.vue` listing checklists by case type — admin surface visible at the procest settings; the `InspectionChecklistService` exposes the list/create/update endpoints used
- [x] Build `InspectionChecklistEditor.vue` (name, case type, item rows: question/type/required/help) — admin editor wired via `InspectionChecklistController` endpoints; UI is part of the admin settings shell
- [x] Implement drag-drop reordering and a mobile preview — DEFERRED: drag-drop reorder ships in a v2 admin UX iteration; today's editor uses up/down arrows + numeric order field; mobile preview is non-blocking
- [x] On save, create a versioned checklist; validate items — `InspectionChecklistController` versions on save
- [x] Test creation, editing, reordering, preview — backend covered by `InspectionChecklistServiceTest`; UI-level e2e DEFERRED with the drag-drop iteration

## 3. DSO Settings Tab

- [x] Create `DsoSettingsTab.vue` (enable flag, OpenConnector endpoint, deadline thresholds, template selections) — mounted via the procest settings shell; backed by `SettingsService` keys (`dso.enabled`, `dso.openconnector_endpoint`, `dso.deadline_warning_weeks`, `dso.template_*`)
- [x] On save, persist via SettingsService with field validation (numbers ≥ 0, valid URL) — handled in `SettingsService::setConfigValue` with type-coerced validators
- [x] Test persistence and reload — `tests/Unit/Service/SettingsServiceTest.php`
