# Implementation Tasks — Signalering Widgets

Issue: #213
Spec: openspec/changes/signalering-widgets/

## Phase 1: Helper Functions & Utils

- [ ] 1.1 Implement `getDeadlineAlerts()` in `src/utils/dashboardHelpers.js`
  - Takes: `cases` array, `caseTypes` array, `warningThreshold` (default 3)
  - Returns: `{ overdue: [...], atRisk: [...] }` with id, title, identifier, caseTypeName, daysOverdue/daysRemaining, handler
  - Logic: Filter open cases, compute days until/past deadline, split into overdue (days < 0) and atRisk (0 <= days < threshold)
  - Sort: overdue descending (most overdue first), atRisk ascending (most urgent first)

- [ ] 1.2 Implement `getTaskDueReminders()` in `src/utils/dashboardHelpers.js`
  - Takes: `tasks` array (filtered to current user), `warningThreshold` (default 3)
  - Returns: `{ overdue: [...], dueSoon: [...] }` with id, title, caseReference, daysOverdue/daysRemaining, priority
  - Logic: Filter tasks with due date, compute days to due, split overdue/dueSoon
  - Exclude: Tasks without due date
  - Sort: overdue descending, dueSoon ascending

- [ ] 1.3 Implement `getStalledCases()` in `src/utils/dashboardHelpers.js`
  - Takes: `cases` array, `caseTypes` array, `stalledThreshold` (default 7)
  - Returns: array of `{ id, title, identifier, caseTypeName, daysSinceActivity, handler }`
  - Logic: Filter open/non-final cases, compute days since `dateModified`, retain only >= threshold
  - Sort: descending (most stalled first)

- [ ] 1.4 Add threshold constants to `src/utils/dashboardHelpers.js`
  - `DEADLINE_WARNING_DAYS = 3`
  - `STALLED_THRESHOLD_DAYS = 7`
  - Export for use in components and tests

## Phase 2: Dashboard Components

- [ ] 2.1 Create/verify `src/views/dashboard/DeadlineAlerts.vue`
  - Props: `cases`, `caseTypes` (from parent dashboard)
  - Computed: call `getDeadlineAlerts()` on mount/when props change
  - Render: overdue cases (red indicator) above atRisk cases (orange indicator)
  - Sorting: by urgency (days remaining ascending for atRisk, days overdue descending for overdue)
  - Empty state: "No deadline alerts" when both arrays empty
  - Click handler: navigate to case detail view (router.push)
  - "View all" link: navigate to cases view with filter applied

- [ ] 2.2 Create/verify `src/views/dashboard/TaskDueReminders.vue`
  - Props: `tasks` (filtered to current user), computed via loadDashboardData()
  - Computed: call `getTaskDueReminders()` on mount/props change
  - Render: overdue tasks (red) above dueSoon tasks (orange/yellow)
  - Display: title, parent case reference, days remaining/overdue, priority badge
  - Empty state: "No task reminders"
  - Click handler: navigate to task detail view
  - Sort: overdue descending, dueSoon ascending

- [ ] 2.3 Create/verify `src/views/dashboard/StalledCases.vue`
  - Props: `cases`, `caseTypes`
  - Computed: call `getStalledCases()`
  - Display: title, identifier, caseTypeName, days since activity, handler
  - Sort: most stalled first
  - Empty state: "All cases active"
  - Click handler: navigate to case detail
  - Exclude final-status cases

## Phase 3: Dashboard Layout Integration

- [ ] 3.1 Register signalering widgets in dashboard layout
  - Update `DEFAULT_LAYOUT` in dashboard view to include row 3:
    - `{ widgetId: 'deadline-alerts', slot: 'col-sm-4' }`
    - `{ widgetId: 'task-reminders', slot: 'col-sm-4' }`
    - `{ widgetId: 'stalled-cases', slot: 'col-sm-4' }`
  - Widgets respect dashboard grid system and user rearrangement

- [ ] 3.2 Update dashboard data loading
  - Ensure `loadDashboardData()` computes widget data (calls helpers)
  - Pass computed `deadlineAlerts`, `taskReminders`, `stalledCases` to components

## Phase 4: Nextcloud Dashboard Widget Adaptation

- [ ] 4.1 Create/verify `lib/Dashboard/DeadlineAlertsWidget.php`
  - Implements Nextcloud `IWidget` interface
  - `getId()` → 'procest-deadline-alerts'
  - `getTitle()` → t('procest', 'Deadline Alerts')
  - `getOrder()` → suitable rank (e.g., 10)
  - `getIconClass()` → calendar/warning icon
  - `getUrl()` → link to procest dashboard
  - Optionally initialize data if widget caches

- [ ] 4.2 Create/verify `lib/Dashboard/TaskRemindersWidget.php`
  - Same interface, ID 'procest-task-reminders'
  - Title: t('procest', 'Task Reminders')
  - Icon: task/checkbox icon

- [ ] 4.3 Create/verify `lib/Dashboard/StalledCasesWidget.php`
  - Same interface, ID 'procest-stalled-cases'
  - Title: t('procest', 'Stalled Cases')
  - Icon: pause/stalled icon

- [ ] 4.4 Register widgets in `lib/AppInfo/Application.php`
  - Call `registerDashboardWidget()` for each of the three widget classes
  - Ensure on app enable/boot

## Phase 5: Nextcloud Widget Vue Components & Webpack

- [ ] 5.1 Create/verify `src/views/widgets/DeadlineAlertsWidget.vue`
  - Receive `userId` from Nextcloud dashboard
  - Fetch top 5 deadline-alert cases via API
  - Render: title, days remaining/overdue, severity (red/orange)
  - Click case: navigate to procest case detail

- [ ] 5.2 Create/verify `src/views/widgets/TaskRemindersWidget.vue`
  - Fetch top 5 task-reminders for current user
  - Render: title, case reference, due date, priority
  - Click task: navigate to task detail

- [ ] 5.3 Create/verify `src/views/widgets/StalledCasesWidget.vue`
  - Fetch top 5 stalled cases
  - Render: title, days inactive, case type, handler

- [ ] 5.4 Create/verify Webpack entry points
  - `src/deadlineAlertsWidget.js` — boot DeadlineAlertsWidget.vue
  - `src/taskRemindersWidget.js` — boot TaskRemindersWidget.vue
  - `src/stalledCasesWidget.js` — boot StalledCasesWidget.vue
  - Register output in `webpack.config.js` (or `package.json` build config)

## Phase 6: Testing & Quality

- [ ] 6.1 Unit tests for helpers
  - `tests/Unit/Utils/DashboardHelpersTest.php` or `.js`
  - Test `getDeadlineAlerts()`: no deadline, upcoming, at-risk, overdue, final-status exclusion
  - Test `getTaskDueReminders()`: no due date, upcoming, overdue, sorting
  - Test `getStalledCases()`: recent activity, stalled, final-status exclusion

- [ ] 6.2 Component tests (Vue/Vitest)
  - Render DeadlineAlerts with various datasets (empty, all overdue, mixed)
  - Test navigation on click
  - Test "View all" link formatting

- [ ] 6.3 Accessibility audit
  - Severity indicators use text labels + color (not color-only)
  - All rows keyboard-navigable
  - Proper aria-labels on buttons/links

- [ ] 6.4 Localization
  - All user-facing text wrapped in `t('procest', '...')`
  - Translation strings: English/Dutch keys in `l10n/en.json`, `l10n/nl.json`

- [ ] 6.5 Static analysis & code style
  - `composer check:strict` clean
  - No forbidden patterns (`var_dump`, `die`, etc.)
  - @spec tags on new PHP classes/methods

- [ ] 6.6 PHPUnit test suite
  - `vendor/bin/phpunit` all green
  - New tests cover acceptance criteria from spec

## Phase 7: Documentation & PR

- [ ] 7.1 Update README.md
  - Document widget availability and configuration
  - Link to spec for details

- [ ] 7.2 Add @spec PHPDoc tags
  - Every new PHP file/class: `@spec openspec/changes/signalering-widgets/tasks.md#task-N`
  - Every new public method: same tag

- [ ] 7.3 Commit & Push
  - Incremental commits per phase
  - Message: `feat(signalering): <description> (#213)`

- [ ] 7.4 Open draft PR
  - Target: `development`
  - Title: `feat: Implement signalering widgets (#213)`
  - Body: Spec reference, changes per file, test coverage
  - First line: `Closes #213`

---

## Progress Tracking

- [ ] Phase 1: Helpers __ / 4
- [ ] Phase 2: Dashboard Components __ / 3
- [ ] Phase 3: Layout __ / 2
- [ ] Phase 4: Widget Adaptation __ / 4
- [ ] Phase 5: Widget Vue & Webpack __ / 4
- [ ] Phase 6: Testing __ / 6
- [ ] Phase 7: PR & Docs __ / 4

**Total**: 27 tasks
