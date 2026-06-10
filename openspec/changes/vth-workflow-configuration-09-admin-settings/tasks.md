# Tasks: vth-workflow-configuration-09-admin-settings


> **Build status (hydra audit 2026-06-10).** Greenfield Vue settings page (Workflows + InspectionChecklists + DSO tabs). All three corresponding backends ship on dev (see members 02, 06, 08). Only the admin Vue surface is open.
VTH settings page + Workflows/InspectionChecklists/DSO tabs. Traces to giant Tasks 17, 18, 10.

## 1. Settings Page & Workflows Tab

- [~] Create `VthConfigurationPage.vue` with tab navigation (admin settings surface, not in vue-router) — greenfield Vue work; backend ready
- [~] Create `WorkflowsTab.vue` listing the three VTH workflows — greenfield
- [~] Per workflow: version, active status, activate/deactivate, view (diagram), download (JSON) — greenfield; `WorkflowDefinitionService::publish()` already supports versioning
- [~] Mount the Leges Rules and Beschikking Templates tabs (from members 04/05) — greenfield
- [~] Test navigation and workflow activation/deactivation — deferred to vth-workflow-configuration-10-testing

## 2. Inspection Checklist Configuration

- [~] Create `InspectionChecklistsTab.vue` listing checklists by case type — greenfield; backend `InspectionChecklistService` already supports CRUD
- [~] Build `InspectionChecklistEditor.vue` (name, case type, item rows: question/type/required/help) — greenfield
- [~] Implement drag-drop reordering and a mobile preview — greenfield
- [~] On save, create a versioned checklist; validate items — greenfield; `InspectionChecklistService` versioning already implemented
- [~] Test creation, editing, reordering, preview — deferred to vth-workflow-configuration-10-testing

## 3. DSO Settings Tab

- [~] Create `DsoSettingsTab.vue` (enable flag, OpenConnector endpoint, deadline thresholds, template selections) — greenfield; persistence via `SettingsService` is ready
- [~] On save, persist via SettingsService with field validation (numbers ≥ 0, valid URL) — greenfield
- [~] Test persistence and reload — deferred to vth-workflow-configuration-10-testing
