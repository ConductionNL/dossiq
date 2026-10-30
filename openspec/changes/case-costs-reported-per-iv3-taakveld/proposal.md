# Case costs reported per Iv3 taakveld

## Why

Row 10.20 (report case costs per Iv3 taakveld, with 2023 refinement codes rolled up to their parent). Decision 261 (Ruben, 30 Oct 2026) puts this report in Shillinq: under hydra ADR-107 (money and effort ownership) Shillinq is the only general ledger and the only Iv3 reporter, and a domain app classifies but never sums money. ADR-107 decision 2 names exactly what dossiq keeps, the taakveld classification of its case types, and what it deletes: its own taakveld list and any Iv3 aggregation.

So the earlier plan of this change, a generic grouped-cost report in dossiq with the Iv3 roll-up as its key map, is withdrawn. Dossiq does three things instead:

1. It keeps `caseType.iv3TaskField` (the classification; the decision text calls it `iv3Taakveld`, the register property has been `iv3TaskField` since the English rename) and lets a beheerder pick the code from Shillinq's `BbvTaakveld` catalogue.
2. It exposes what Shillinq needs through the OpenRegister objects it already has: the case, its `caseType` reference and that type's `iv3TaskField`. Hours on a case are humaniq `TimeEntry` records carrying the case reference (`domainObjectRef`, `domainObjectType: dossiq:case`), written through the `humaniq-hours` widget that is already on the case. No dossiq endpoint, no money field, no sum.
3. The reports page gets a link card "Kosten per taakveld staat in Shillinq" that opens Shillinq's Iv3 report (`Iv3ReportGenerator`, board ShOverheid).

## What exists and what goes

- `lib/Service/Iv3TaakveldList.php`, `lib/Settings/iv3_taakvelden.json` (version `iv3-bbv-v2`), `lib/Controller/Iv3TaakveldController.php` and route `GET /api/reports/iv3/taakvelden` duplicate Shillinq's `BbvTaakveld` catalogue (`lib/Settings/seeds/bbv-taakvelden-*.json`). ADR-107: "No other app ships a taakveld list." They are retired by this change. No frontend calls the route (the case type form renders `iv3TaskField` as a plain field).
- One thing the dossiq list has that Shillinq's catalogue does not: the 18 official 2023 Wmo/Jeugd refinement codes (`6.71a` to `6.82b`) with `aggregatesUnder` their pre-2023 parent. ADR-107 says that roll-up "MUST be preserved by Shillinq's catalogue or explicitly dropped with a reason". The paired Shillinq change `iv3-report-takes-case-costs-by-case-type` adds them to the catalogue; dossiq's list is deleted only after that lands.
- `Iv3ReportService` was already gone (ADR-107); the main spec still describes it. This change removes that requirement and the two that describe the dossiq list.

## What changes

- Spec `iv3-taakveld-2023-refinement`: the three requirements about the dossiq list and the dossiq report are removed; three requirements say what dossiq does now (classify, expose, link out, never sum money).
- Code (tasks): retire the list, controller, route and their tests; the case type form picks the code from Shillinq's catalogue when Shillinq is installed; the reports page link card, which needs a `href` card in nextcloud-vue's `CnReportsPage`.
- Board DqRapportages: the opened Iv3 cost report and the "Kosten per zaaktype" card leave the board; the link card takes their place (design-system PR).

## Impact

- `lib/Service/Iv3TaakveldList.php`, `lib/Settings/iv3_taakvelden.json`, `lib/Controller/Iv3TaakveldController.php`, `appinfo/routes.php`, their unit tests (deleted); `lib/Settings/dossiq_register.json` (`iv3TaskField` description); `src/manifest.json` (case type field, Reports card).
- Depends on Shillinq `iv3-report-takes-case-costs-by-case-type` (catalogue refinement codes, report source) and a nextcloud-vue `CnReportsPage` card `href`.
