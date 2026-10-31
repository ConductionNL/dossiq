# Tasks: vth-cases-run-end-to-end

## 1. Package admin tab (generic)

- [ ] 1.1 Package manifest key `adminTab` (`title`, `lists`: any of `caseTypes`, `flows`, `feeRules`, `templates`, `connections`, `checklists`) documented next to the installer that reads `package.json`; `tests/Unit/Service/Package*Test.php` covers parsing.
- [ ] 1.2 An endpoint (or initial state from `lib/Settings/AdminSettings.php`) that lists installed packages with an `adminTab` and the ids of what each installed, read from the package ledger.
- [ ] 1.3 `src/views/settings/AdminRoot.vue`: one tab per package from 1.2, each list row opening the existing generic editor (case type, flow, fee rule, template, connection, checklist).
- [ ] 1.4 `lib/Settings/packages/vth/package.json`: `adminTab` with title "VTH" and all six lists; bump the version.

## 2. DSO actions on the case

- [ ] 2.1 `src/dialogs/DsoForwardDialog.vue` and `src/dialogs/DsoCollaborationDialog.vue` calling `POST /api/dso/cases/{caseId}/doorsturen` and `/samenwerking`.
- [ ] 2.2 `src/manifest.json` CaseDetail `headerActions` `dso-forward` and `dso-collaborate`, `visibleWhen` the case's source is DSO.

## 3. Inspection on the case

- [ ] 3.1 Case type property `inspectionChecklist` (template id); the VTH package sets it on the supervision case types.
- [ ] 3.2 CaseDetail places the inspection checklist widget with `visibleWhen: "@context.caseType.inspectionChecklist"` (the run is the inspection task of `inspection-checklists-onto-task`).

## 4. Tests and checks

- [ ] 4.1 `tests/e2e/package-admin-tab.spec.ts`: both scenarios of REQ-VAS-PKG-01.
- [ ] 4.2 Newman: forward and collaborate calls against the DSO mock in `tests/integration/packages/dso-intake.postman_collection.json`.
- [ ] 4.3 Live pass against DqVthInstellingen, DqZaaktypen and DqInspectie.
- [ ] 4.4 `openspec/parity/capabilities.json` row 11.56: built when 1 to 3 are live.
