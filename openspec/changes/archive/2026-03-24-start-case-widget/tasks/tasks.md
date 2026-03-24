# Start Case Widget — Tasks

## Task List

- [x] **T1: Create PHP widget class** [MVP]
  - File: `lib/Dashboard/StartCaseWidget.php`
  - Create `StartCaseWidget` implementing `OCP\Dashboard\IWidget`
  - Follow exact pattern of `CasesOverviewWidget.php`
  - Acceptance: Class passes `php -l` syntax check and follows PSR-12

- [x] **T2: Register widget in Application.php** [MVP]
  - File: `lib/AppInfo/Application.php`
  - Add `use OCA\Procest\Dashboard\StartCaseWidget;`
  - Add `$context->registerDashboardWidget(StartCaseWidget::class);`
  - Acceptance: Widget appears in `register()` method alongside other widgets

- [x] **T3: Create Vue component** [MVP]
  - File: `src/views/widgets/StartCaseWidget.vue`
  - Fetch case types via `useObjectStore().fetchCollection('caseType')`
  - Render as clickable cards with title
  - Handle click → create case → navigate
  - Show empty state and loading state
  - All strings wrapped in `t('procest', '...')`
  - Acceptance: SCN-001 through SCN-005

- [x] **T4: Create webpack entry point** [MVP]
  - File: `src/startCaseWidget.js`
  - Register via `OCA.Dashboard.register('procest_start_case_widget', ...)`
  - Follow pattern of `casesOverviewWidget.js`
  - Acceptance: Entry file matches existing widget entry pattern

- [x] **T5: Add webpack config entry** [MVP]
  - File: `webpack.config.js`
  - Add `startCaseWidget` entry pointing to `src/startCaseWidget.js`
  - Output filename: `procest-startCaseWidget.js`
  - Acceptance: Entry added alongside other widget entries

- [x] **T6: Quality checks** [MVP]
  - Run `php -l` on new PHP file
  - Verify all files exist and follow existing patterns
  - Check i18n coverage (all user-visible strings use `t()`)
  - Acceptance: No syntax errors, pattern consistency verified
