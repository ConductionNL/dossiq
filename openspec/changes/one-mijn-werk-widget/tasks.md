# Tasks: one-mijn-werk-widget

### Task 1: Mijn werk counts the queue
- **spec_ref**: `openspec/changes/one-mijn-werk-widget/specs/dashboard/spec.md#requirement-my-open-work-is-a-start-page-widget-that-any-dashboard-can-render-req-dash-023`
- **files**: `lib/Dashboard/OpenWorkSummary.php`, `lib/Dashboard/MyOpenWorkWidget.php`, `l10n/en.json`, `l10n/nl.json`
- [x] Implement
- [x] Test: `tests/Unit/Dashboard/MyOpenWorkWidgetTest.php`, `tests/e2e/my-open-work-widget.spec.ts`

### Task 2: My tasks and Overdue cases leave the dashboard
- **spec_ref**: `openspec/changes/one-mijn-werk-widget/specs/dashboard/spec.md`
- **files**: `lib/AppInfo/Registrar/DashboardWidgetList.php`, `lib/Dashboard/MyTasksWidget.php`, `lib/Dashboard/OverdueCasesWidget.php`, `lib/Dashboard/DeadlineAlertsWidget.php`, `lib/Dashboard/StalledCasesWidget.php`, `lib/Dashboard/TaskRemindersWidget.php`
- [x] Implement
- [x] Test: `tests/Unit/AppInfo/DashboardWidgetListTest.php`, `tests/Unit/Dashboard/DashboardPageWidgetsGoldenTest.php`, `tests/Unit/Dashboard/SignaleringWidgetsTest.php`
