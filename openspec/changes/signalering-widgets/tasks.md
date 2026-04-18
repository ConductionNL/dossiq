# Tasks: signalering-widgets

## Backend Services

- [ ] **T01**: Create SignaleringService.php with deadline calculation logic
  - calculateDeadlineStatus(Case, CaseType): array
  - checkThresholds(Case, CaseType): bool
  - Add unit tests (3+ methods)

- [ ] **T02**: Create NotificationService.php extension
  - notifyDeadlineWarning(Case, channel): void
  - dispatchInAppNotification(Case): void
  - dispatchEmailNotification(Case): void
  - Add unit tests (3+ methods)

- [ ] **T03**: Create SignaleringService unit tests
  - Test deadline calculation without opschorting
  - Test deadline calculation with opschorting suspension
  - Test threshold detection (warning, on-track, overdue)
  - Test with missing zaaktype configuration

- [ ] **T04**: Create NotificationService unit tests
  - Test in-app notification dispatch
  - Test email webhook payload generation
  - Test notification with user preferences

## Controllers & API

- [ ] **T05**: Create SignaleringConfigController.php
  - GET /api/signalering/config — List configurations
  - POST /api/signalering/config — Create/update
  - DELETE /api/signalering/config/:zaaktypeId — Remove
  - Per-object authorization checks

- [ ] **T06**: Create DeadlineNotificationController.php
  - GET /api/cases/:caseId/deadlines — Get deadline status
  - POST /api/deadlines/notify — n8n webhook callback
  - Add @spec PHPDoc tags

- [ ] **T07**: Integration tests for SignaleringConfigController
  - Test CRUD operations
  - Test authorization (admin only for POST/DELETE)
  - Test filtering by zaaktype

- [ ] **T08**: Integration tests for DeadlineNotificationController
  - Test GET /deadlines endpoint returns correct status
  - Test webhook callback updates case state

## Vue Components & UI

- [ ] **T09**: Create UpcomingDeadlinesWidget.vue
  - Implements IDashboardWidget
  - Fetches upcoming deadlines for current user
  - Color-codes rows (green/orange/red)
  - Click-through to case detail
  - Add to dashboard widget registry

- [ ] **T10**: Create DeadlineIndicator.vue
  - Reusable component for inline deadline status
  - Shows streeftermijn + fatale termijn dates
  - Tooltip with days remaining
  - Color coding per status

- [ ] **T11**: Create SignaleringSettingsPage.vue
  - Admin settings at /admin/signalering
  - Per-zaaktype configuration form
  - Save/load from API
  - Test in browser (manual)

- [ ] **T12**: Create DeadlinesOverviewPage.vue
  - Management view at /deadlines/overview
  - Table with case ID, zaaktype, handler, status, days
  - Filters by zaaktype, team, status
  - Export to CSV

- [ ] **T13**: Integrate DeadlineIndicator into Werkvoorraad (Cases table)
  - Add deadline status column
  - Show color badge per case
  - Click-through to deadline detail

- [ ] **T14**: Integrate deadline section into Case detail view
  - Add "Termijnbewaking" header section
  - Show streeftermijn + fatale termijn dates
  - Show opschorting suspension info if active
  - Timeline of deadline events

## Configuration & Settings

- [ ] **T15**: Register signaleringConfig schema in OpenRegister
  - Schema: zaaktypeId (required), warningDaysStreef, warningDaysFatale, notificationChannels (array), enabled
  - Add @spec PHPDoc tag

- [ ] **T16**: Add signalering settings to admin settings page
  - Default warning thresholds (7 days streeftermijn, 0 days fatale)
  - Enable/disable notifications globally
  - Test form persistence

## Event Handling & Triggers

- [ ] **T17**: Hook SignaleringService into case creation/update
  - Call calculateDeadlineStatus() on case save
  - Call checkThresholds() to determine if notification needed
  - Call triggerNotifications() if threshold crossed

- [ ] **T18**: Implement n8n webhook integration
  - POST endpoint: /api/deadlines/notify for webhook callbacks
  - Validate webhook signature (if n8n configured)
  - Update case notification state on callback

## Quality Gates

- [ ] **T19**: Run all quality checks
  - `composer check:strict` — must be green
  - `composer test:unit` — all tests pass
  - `npm run lint` — no JS/Vue issues

- [ ] **T20**: Manual testing in browser
  - Create a case with deadline
  - Verify dashboard widget appears
  - Verify color indicators in werkvoorraad
  - Verify case detail shows deadline info
  - Verify admin settings save correctly

- [ ] **T21**: Verify @spec PHPDoc tags
  - Every new PHP file has class-level @spec tag
  - Every public method has @spec tag
  - Format: @spec openspec/changes/signalering-widgets/tasks.md#task-N
