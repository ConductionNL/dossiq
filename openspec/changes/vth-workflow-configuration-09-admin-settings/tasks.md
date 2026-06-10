# Tasks: vth-workflow-configuration-09-admin-settings


> **Build status (hydra audit 2026-06-10).** Greenfield Vue settings page (Workflows + InspectionChecklists + DSO tabs). All three corresponding backends ship on dev (see members 02, 06, 08). Only the admin Vue surface is open.
VTH settings page + Workflows/InspectionChecklists/DSO tabs. Traces to giant Tasks 17, 18, 10.

## 1. Settings Page & Workflows Tab

- [ ] Create `VthConfigurationPage.vue` with tab navigation (admin settings surface, not in vue-router)
- [ ] Create `WorkflowsTab.vue` listing the three VTH workflows
- [ ] Per workflow: version, active status, activate/deactivate, view (diagram), download (JSON)
- [ ] Mount the Leges Rules and Beschikking Templates tabs (from members 04/05)
- [ ] Test navigation and workflow activation/deactivation

## 2. Inspection Checklist Configuration

- [ ] Create `InspectionChecklistsTab.vue` listing checklists by case type
- [ ] Build `InspectionChecklistEditor.vue` (name, case type, item rows: question/type/required/help)
- [ ] Implement drag-drop reordering and a mobile preview
- [ ] On save, create a versioned checklist; validate items
- [ ] Test creation, editing, reordering, preview

## 3. DSO Settings Tab

- [ ] Create `DsoSettingsTab.vue` (enable flag, OpenConnector endpoint, deadline thresholds, template selections)
- [ ] On save, persist via SettingsService with field validation (numbers ≥ 0, valid URL)
- [ ] Test persistence and reload
