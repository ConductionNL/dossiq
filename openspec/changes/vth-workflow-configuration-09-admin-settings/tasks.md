# Tasks: vth-workflow-configuration-09-admin-settings

VTH settings page + Workflows/InspectionChecklists/DSO tabs. Traces to giant Tasks 17, 18, 10.

## 1. Settings Page & Workflows Tab

- [~] Create `VthConfigurationPage.vue` with tab navigation (admin settings surface, not in vue-router) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Create `WorkflowsTab.vue` listing the three VTH workflows — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Per workflow: version, active status, activate/deactivate, view (diagram), download (JSON) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Mount the Leges Rules and Beschikking Templates tabs (from members 04/05) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test navigation and workflow activation/deactivation — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. Inspection Checklist Configuration

- [~] Create `InspectionChecklistsTab.vue` listing checklists by case type — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Build `InspectionChecklistEditor.vue` (name, case type, item rows: question/type/required/help) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement drag-drop reordering and a mobile preview — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] On save, create a versioned checklist; validate items — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test creation, editing, reordering, preview — deferred to downstream cycle / fleet-wide adoption (handoff)

## 3. DSO Settings Tab

- [~] Create `DsoSettingsTab.vue` (enable flag, OpenConnector endpoint, deadline thresholds, template selections) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] On save, persist via SettingsService with field validation (numbers ≥ 0, valid URL) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test persistence and reload — deferred to downstream cycle / fleet-wide adoption (handoff)
