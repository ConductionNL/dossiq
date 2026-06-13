---
kind: code
depends_on:
  - vth-workflow-configuration-04-leges-config-ui
  - vth-workflow-configuration-05-beschikking-generation
  - vth-workflow-configuration-06-mobile-inspection
  - vth-workflow-configuration-08-dso-integration
chain:
  - vth-workflow-configuration-01-config-foundation
  - vth-workflow-configuration-02-workflow-templates
  - vth-workflow-configuration-03-leges-engine
  - vth-workflow-configuration-04-leges-config-ui
  - vth-workflow-configuration-05-beschikking-generation
  - vth-workflow-configuration-06-mobile-inspection
  - vth-workflow-configuration-07-lhso-classification
  - vth-workflow-configuration-08-dso-integration
  - vth-workflow-configuration-09-admin-settings
  - vth-workflow-configuration-10-testing
  - vth-workflow-configuration-11-quality-docs
---

# VTH Workflow Configuration — 09 Admin Settings

> Member 9 of 11 in the `vth-workflow-configuration` ADR-032 chain. `kind: code`. Depends on the UI-bearing members it hosts (04 leges, 05 beschikking, 06 mobile/inspection, 08 DSO). Builds the VTH settings page shell, the Workflows tab, the inspection-checklist configuration tab, and the DSO settings tab.

## Summary

Implement the VTH configuration settings page (`VthConfigurationPage.vue`) with tab navigation, the Workflows tab (`WorkflowsTab.vue`) for activating/deactivating workflow versions, the inspection-checklist configuration tab (`InspectionChecklistsTab.vue` + `InspectionChecklistEditor.vue`), and the DSO settings tab (`DsoSettingsTab.vue`). The Leges and Beschikking tabs (built in members 04/05) are mounted here. Traces to giant Tasks 17, 18, and 10.

## Scope

### In Scope

- `VthConfigurationPage` shell + tab navigation, mounting all VTH config tabs.
- `WorkflowsTab` (list/activate/deactivate/view/download workflow versions).
- `InspectionChecklistsTab` + `InspectionChecklistEditor` (giant Task 10).
- `DsoSettingsTab` (giant Task 18).

### Out of Scope

- Leges/Beschikking editors (members 04/05) — only mounted here.
- Backend services (members 02–08).

## Dependencies

- **vth-workflow-configuration-04-leges-config-ui**, **05-beschikking-generation**: tabs mounted in the page.
- **vth-workflow-configuration-06-mobile-inspection**: checklist data the checklist editor configures.
- **vth-workflow-configuration-08-dso-integration**: DSO settings consumed by the integration.

## Acceptance Criteria

1. GIVEN the settings page, WHEN opened, THEN tabs for Workflows, Leges Rules, Beschikking Templates, Inspection Checklists, and DSO Settings are shown.
2. GIVEN the Workflows tab, WHEN an admin activates/deactivates a version, THEN the workflow state updates.
3. GIVEN the DSO settings tab, WHEN an admin saves configuration, THEN settings persist via SettingsService.
