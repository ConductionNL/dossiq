# Tasks: case-costs-reported-per-iv3-taakveld

- [ ] 1 `lib/Service/Reporting/GroupedCostReport.php`: `build(string $groupField, DateTimeImmutable $from, DateTimeImmutable $to, ?callable $keyMap): array` summing the case's costed hours (the hours leaf read: hrmq time entries carrying the case reference). A grant settlement is not a cost source: it writes nothing to the case (subsidie-settlement-case-costs REQ-SSC-005, decision 258); `tests/Unit/Service/Reporting/GroupedCostReportTest.php` with the roll-up case of REQ-IV3-REPORT-01.
- [ ] 2 Route `GET /api/reports/grouped-costs` in `appinfo/routes.php` on a reporting controller, reporting audience gate; `keyMap=iv3` selects `Iv3TaakveldList::aggregationKeyFor()`.
- [ ] 3 `src/manifest.json` page `Reports`: an Iv3 card (quarter picker, table, CSV export through the generic `export` action).
- [ ] 4 `tests/e2e/iv3-report.spec.ts`: both scenarios.
- [ ] 5 Board DqRapportages (reports page with the Iv3 card) does not exist yet: a board lane draws it (gap list b); then the live pass against it.
- [ ] 6 `openspec/parity/capabilities.json` row 10.20: built, board DqRapportages once drawn.
