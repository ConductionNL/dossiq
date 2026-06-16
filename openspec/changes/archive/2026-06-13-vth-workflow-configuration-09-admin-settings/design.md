# Design: vth-workflow-configuration-09-admin-settings

## Architecture

`kind: code` member (ADR-032). Vue admin settings UI (ADR-004). The page is an admin settings surface rendered by Nextcloud's settings framework — it is NOT added to the in-app vue-router (admin-router rule). Tabs delegate to child components; editor dialogs live in their own files (modal-isolation); NcSelect carries inputLabel (a11y).

## Component Layout

- `VthConfigurationPage.vue` — tab navigation hosting all VTH config tabs.
- `WorkflowsTab.vue` — list Omgevingsvergunning/Toezichtzaak/Handhavingszaak workflows; per-workflow version, active status, activate/deactivate, view (diagram), download (JSON backup).
- `InspectionChecklistsTab.vue` + `InspectionChecklistEditor.vue` — create/edit checklists by case type with items (question, type, required, help text), drag-drop reorder, mobile preview, versioned save.
- `DsoSettingsTab.vue` — enable flag, OpenConnector endpoint, deadline thresholds, beschikking-template selections; persisted via SettingsService.
- Leges and Beschikking tabs (members 04/05) are mounted by the page.

## Security (ADR-005)

All VTH settings are admin-only, enforced server-side by the SettingsService/admin-gated endpoints. Settings fields are validated (numbers ≥ 0, endpoint a valid URL). No server data is read from DOM data-attributes — initial state / API only.
