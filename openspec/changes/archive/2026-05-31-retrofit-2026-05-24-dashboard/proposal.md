# Retrofit — dashboard

Describes observed behavior of 4 PHP files (~23 methods) — the three remaining Nextcloud dashboard widgets (CasesOverview, MyTasks, StartCase) plus the in-app `DashboardController` — as 3 new REQs (REQ-DASH-016..018) extending the dashboard capability.

## Affected code units
- lib/Dashboard/CasesOverviewWidget.php (7 methods)
- lib/Dashboard/MyTasksWidget.php (7 methods)
- lib/Dashboard/StartCaseWidget.php (7 methods)
- lib/Controller/DashboardController.php (2 methods)

## Approach
- File-level survey; all 3 widgets share the IWidget shape covered by signalering-widgets REQ-001
- One REQ for the widget set, one for the controller API surface, one for boot-time registration

Source: openspec/coverage-report.md generated 2026-05-24. Tracks ConductionNL/procest#565.
