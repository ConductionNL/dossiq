# Retrofit — signalering-widgets

Describes observed behavior of 4 Nextcloud `IWidget` implementations (DeadlineAlerts, OverdueCases, StalledCases, TaskReminders) as 3 new REQs covering the widget class contract, data-loading lifecycle, and dashboard wiring.

## Affected code units
- lib/Dashboard/DeadlineAlertsWidget.php (7 methods)
- lib/Dashboard/OverdueCasesWidget.php (7 methods)
- lib/Dashboard/StalledCasesWidget.php (7 methods)
- lib/Dashboard/TaskRemindersWidget.php (7 methods)

## Approach
- All 4 widgets implement `OCP\Dashboard\IWidget` with the same method shape
- One REQ per cross-cutting concern (class contract, load lifecycle, dashboard wiring)
- Bucket-1 file-level `@spec` already in place; this retrofit adds the per-task pointers

Source: openspec/coverage-report.md generated 2026-05-24. Tracks ConductionNL/procest#565.
