# VTH cases run end to end

## Why

Row 11.56 (run permit, supervision and enforcement cases from seeded VTH case types and workflows). The configuration ships: `vth-package` (fully ticked) installs six case types, their statuses, roles, fields, document types, four published and six draft flows and three inspection checklists (`lib/Settings/packages/vth/`, `install: auto`). What a user cannot do on development `a5c9b4153`:

- Board DqVthInstellingen draws a VTH tab in dossiq's Nextcloud admin settings (case types, flows, fees, decision templates, DSO connection, checklists in one place). It does not exist; the parts are spread over generic settings pages and some have none.
- Nothing under `src/` calls the DSO routes (`/api/dso/cases/{caseId}/transition`, `/beschikking`, `/samenwerking`, `/doorsturen`), so a DSO-sourced permit cannot be forwarded or answered from the case.
- The inspection and enforcement steps are not placed on the VTH cases (the enforcement step is `enforcement-intervention-from-the-case`).

## What changes

- A package can declare an admin settings tab (`package.json` `adminTab`: title and the configuration lists it shows). The VTH package declares the tab DqVthInstellingen draws. The tab is generic code: any package can declare one, nothing in it is named after VTH (decision 182).
- DSO actions on the case: forward to another authority and start a collaboration, as header actions visible on cases that came in through DSO (the case's `source` is DSO), calling the existing routes.
- The inspection checklist widget is placed on case types that name an inspection checklist (`caseType.inspectionChecklist`), not by case type name.

## Impact

- `lib/Settings/packages/vth/package.json` (`adminTab`), the package installer reading it, `src/views/settings/AdminRoot.vue` rendering package tabs.
- `src/manifest.json` CaseDetail: header actions `dso-forward`, `dso-collaborate`; widget placement for the inspection checklist.
- Spec: requirements added to `vth-admin-settings`.
