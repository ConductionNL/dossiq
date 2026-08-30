# Retrofit — annotate procest against existing specs

Retroactive annotation of 19 files across 5 capabilities against 60 REQs.
No code logic changes. No spec deltas (all REQs already exist in `openspec/specs/`).

Source: `openspec/coverage-report.md` generated 2026-05-24 (Bucket 1).

Files in scope:

- **zgw-api-mapping** (21 REQs) — 7 files: ZRC/DRC/ZTC/BRC/NRC controllers + ZgwMappingController + ZgwService (~163 methods)
- **prometheus-metrics** (10 REQs) — MetricsController (13 methods)
- **case-types** (17 REQs) — CaseDefinitionController + CaseDefinitionExportService + CaseDefinitionImportService (~14 methods)
- **signalering-widgets** (6 REQs) — DeadlineAlertsWidget + TaskRemindersWidget + StalledCasesWidget + OverdueCasesWidget (~28 methods)
- **dashboard** (16 REQs) — CasesOverviewWidget + MyTasksWidget + StartCaseWidget + DashboardController (~21 methods)

The coverage scan deferred per-method confidence scoring (v1 limitation; see notes
in `coverage-report.json`). This annotation pass therefore applies file-level
`@spec` tags only — methods inherit the file-level tag per the existing
procest convention (see `lib/Service/KpiAggregationService.php` as reference,
where T01 lives on the file docblock plus the first method).

Voorstel-management Bucket 1 entries are excluded — the spec is being moved to
`parafering-actions` in pending PR #566.

See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
