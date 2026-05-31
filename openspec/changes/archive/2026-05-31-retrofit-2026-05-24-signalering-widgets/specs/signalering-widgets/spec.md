---
retrofit_extensions:
  - REQ-001
  - REQ-002
  - REQ-003
---

# Signalering Widgets — IWidget implementation shape (retrofit)

## Requirements

### REQ-001: Each signalering widget SHALL implement Nextcloud IWidget with id/title/order/icon/url metadata

Each of `DeadlineAlertsWidget`, `OverdueCasesWidget`, `StalledCasesWidget`, and `TaskRemindersWidget` SHALL implement `OCP\Dashboard\IWidget` and expose the canonical metadata via `getId()`, `getTitle()`, `getOrder()`, `getIconClass()`, and `getUrl()`. The widget id SHALL be a stable kebab-case identifier (`procest-deadline-alerts`, `procest-overdue-cases`, `procest-stalled-cases`, `procest-task-reminders`) used by both the Nextcloud dashboard registry and the in-app `widgetDefs` map. The title SHALL be returned through `IL10N::t()` so it follows the user locale.

#### Scenario: Widget id is stable for layout persistence
- **WHEN** `DeadlineAlertsWidget::getId()` is called
- **THEN** it SHALL return `'procest-deadline-alerts'` (verbatim, not derived from class name)
- **AND** the same id SHALL be used in `DEFAULT_LAYOUT` row 3 so saved user layouts stay valid across deployments

### REQ-002: Each signalering widget SHALL register its frontend bundle via load()

`IWidget::load()` SHALL call `Util::addScript('procest', '<widget>-main')` and `Util::addStyle('procest', '<widget>-main')` for the widget's compiled Vue bundle. The widget SHALL NOT compute its data payload server-side in PHP — the Vue component fetches data via the Procest API after mounting. The PHP class exists solely to register the widget with Nextcloud's dashboard system and load the frontend bundle.

#### Scenario: Widget bundle is loaded when dashboard is opened
- **WHEN** a user opens the Nextcloud Dashboard with the Deadline Alerts widget in their layout
- **THEN** `DeadlineAlertsWidget::load()` SHALL run and the `deadlineAlertsWidget-main.js` + `.css` bundles SHALL be added to the page

### REQ-003: All four signalering widgets SHALL be registered at app boot via Application

`OCA\Procest\AppInfo\Application::register()` SHALL register all four signalering widgets with the Nextcloud dashboard container so they appear in the dashboard widget picker. Registration order in `Application` SHALL match the canonical `getOrder()` return values to avoid layout drift between dashboard picker and saved user layouts.

#### Scenario: Widgets appear in the dashboard picker
- **WHEN** an authenticated user opens the dashboard widget picker
- **THEN** all four signalering widgets SHALL be listed and clickable
- **AND** each widget's icon + title SHALL match its `getIconClass()` + `getTitle()` return values
