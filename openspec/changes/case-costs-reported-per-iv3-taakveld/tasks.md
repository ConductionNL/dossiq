# Tasks: case-costs-reported-per-iv3-taakveld

- [ ] 1 Before deleting anything: count case types whose `iv3TaskField` is not a code in Shillinq's `BbvTaakveld` catalogue (dev instance and every reachable install). Once Shillinq `iv3-report-takes-case-costs-by-case-type` has shipped the 2023 refinement codes, that count SHALL be zero; a non-zero count is reported, not converted (REQ-IV3-CLASS-01).
- [ ] 2 Retire the duplicate list: delete `lib/Service/Iv3TaakveldList.php`, `lib/Settings/iv3_taakvelden.json`, `lib/Controller/Iv3TaakveldController.php`, the route `iv3Taakveld#taakvelden` in `appinfo/routes.php` and their unit tests; grep shows no other caller (ADR-107 decision 2). Only after task 1 and the Shillinq catalogue change.
- [ ] 3 `lib/Settings/dossiq_register.json` and `dossiq_mock_register.json`: `iv3TaskField` description names Shillinq's `BbvTaakveld` catalogue as the code list, not `Iv3TaakveldList`, and states that dossiq reports nothing from it (REQ-IV3-CLASS-01, REQ-IV3-CLASS-02).
- [ ] 4 `src/manifest.json` CaseTypeDetail: `iv3TaskField` is a lookup on Shillinq's `BbvTaakveld` (register `shillinq`, `requiredApp: shillinq`) showing code and translated label; without Shillinq a plain code field with the hint "Installeer Shillinq om uit de BBV-lijst te kiezen" (REQ-IV3-CLASS-01).
- [ ] 5 nextcloud-vue `CnReportsPage`: a card MAY carry `href` instead of `route`, plus `requiredApp`; a card whose app is missing shows its `unavailable` sentence and no link. Build it there (separate PR on nextcloud-vue), with a vitest.
- [ ] 6 `src/manifest.json` page `Reports`: card `ShillinqIv3` "Kosten per taakveld staat in Shillinq", `href` to Shillinq's reporting page, `requiredApp: shillinq`, category `costs` (REQ-IV3-CLASS-03).
- [ ] 7 `tests/e2e/iv3-link-to-shillinq.spec.ts`: the card opens Shillinq; without Shillinq it shows the sentence and no figure; no money figure per taakveld anywhere on the Reports page.
- [ ] 8 Board DqRapportages amended to the link card (design-system PR); then the live pass against it.
- [ ] 9 `openspec/parity/capabilities.json` row 10.20: provider shillinq, dossiq classifies; built once tasks 2 to 7 land.
