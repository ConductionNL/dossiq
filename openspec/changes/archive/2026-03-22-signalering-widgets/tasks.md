## 1. Helper Functions [V1]

- [x] 1.1 Add `getDeadlineAlerts(openCases, caseTypes, warningDays = 3)` to `src/utils/dashboardHelpers.js` — returns `{ overdue: [], atRisk: [] }` with display-ready objects (id, title, identifier, caseTypeName, daysOverdue/daysRemaining, handler). Uses existing `isCaseOverdue` and `getDaysRemaining` from caseHelpers.
- [x] 1.2 Add `getTaskDueReminders(tasks, warningDays = 3)` to `src/utils/dashboardHelpers.js` — returns `{ overdue: [], dueSoon: [] }` with display-ready objects (id, title, caseReference, daysOverdue/daysRemaining, priority). Filters out tasks without dueDate.
- [x] 1.3 Add `getStalledCases(openCases, caseTypes, stalledDays = 7)` to `src/utils/dashboardHelpers.js` — returns array of stalled cases using `dateModified` (fallback `dateCreated`), each with id, title, identifier, caseTypeName, daysSinceActivity, handler. Sorted by daysSinceActivity descending.
- [x] 1.4 Add threshold constants `DEADLINE_WARNING_DAYS = 3` and `STALLED_THRESHOLD_DAYS = 7` as named exports in `src/utils/dashboardHelpers.js`.

## 2. Dashboard Vue Components [V1]

- [x] 2.1 Create `src/views/dashboard/DeadlineAlerts.vue` — receives `overdue` and `atRisk` arrays as props. Shows overdue section (red indicator) above at-risk section (yellow indicator). Each row: title, identifier, case type, days info, handler. Click navigates to case detail. "View all" link to Cases view with deadline filter. Empty state: "No deadline alerts".
- [x] 2.2 Create `src/views/dashboard/TaskDueReminders.vue` — receives `overdue` and `dueSoon` arrays as props. Shows overdue tasks (red) above due-soon tasks (yellow). Each row: title, case reference, days info, priority badge. Click navigates to task detail. Empty state: "No task reminders".
- [x] 2.3 Create `src/views/dashboard/StalledCases.vue` — receives `stalledCases` array as prop. Each row: title, identifier, case type, days inactive, handler. Click navigates to case detail. Empty state: "All cases active".

## 3. Dashboard Integration [V1]

- [x] 3.1 Update `src/views/Dashboard.vue` — add three new widget definitions to `widgetDefs` computed property: `deadline-alerts`, `task-due-reminders`, `stalled-cases`.
- [x] 3.2 Update `DEFAULT_LAYOUT` in `Dashboard.vue` to add row 3 with the three signalering widgets (4 cols each at gridY: 6).
- [x] 3.3 Add data properties for `deadlineAlerts`, `taskDueReminders`, `stalledCases` and compute them in `loadDashboardData()` using the new helper functions after the existing data is loaded.
- [x] 3.4 Add `<template #widget-deadline-alerts>`, `<template #widget-task-due-reminders>`, `<template #widget-stalled-cases>` slots in the CnDashboardPage template, wiring props to the new components.
- [x] 3.5 Add i18n strings for all new widget labels, empty states, and alert messages (English and Dutch).

## 4. Nextcloud Dashboard Widgets (PHP) [V1]

- [x] 4.1 Create `lib/Dashboard/DeadlineAlertsWidget.php` implementing `OCP\Dashboard\IWidget` — id: `procest_deadline_alerts_widget`, title: "Deadline Alerts", loads `procest-deadlineAlertsWidget` script.
- [x] 4.2 Create `lib/Dashboard/TaskRemindersWidget.php` implementing `OCP\Dashboard\IWidget` — id: `procest_task_reminders_widget`, title: "Task Reminders", loads `procest-taskRemindersWidget` script.
- [x] 4.3 Create `lib/Dashboard/StalledCasesWidget.php` implementing `OCP\Dashboard\IWidget` — id: `procest_stalled_cases_widget`, title: "Stalled Cases", loads `procest-stalledCasesWidget` script.
- [x] 4.4 Register the three new widgets in `lib/AppInfo/Application.php` `register()` method using `$context->registerDashboardWidget()`.

## 5. Nextcloud Widget Vue Components [V1]

- [x] 5.1 Create `src/views/widgets/DeadlineAlertsWidget.vue` — standalone widget component that fetches cases/caseTypes from OpenRegister, computes deadline alerts, and renders top 5 items. Click navigates to Procest.
- [x] 5.2 Create `src/views/widgets/TaskRemindersWidget.vue` — standalone widget component that fetches user tasks, computes due reminders, and renders top 5 items.
- [x] 5.3 Create `src/views/widgets/StalledCasesWidget.vue` — standalone widget component that fetches cases, computes stalled cases, and renders top 5 items.
- [x] 5.4 Create webpack entry points: `src/deadlineAlertsWidget.js`, `src/taskRemindersWidget.js`, `src/stalledCasesWidget.js` — each mounting the corresponding widget Vue component.
- [x] 5.5 Add the three new entry points to `webpack.config.js`.

## 6. Registration and Config [V1]

- [x] 6.1 Update `appinfo/info.xml` to register the three new dashboard widgets if required by Nextcloud's widget discovery.
- [x] 6.2 Verify `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan) for the new PHP widget classes.
- [x] 6.3 Verify `npm run build` succeeds with the new webpack entry points and Vue components.
