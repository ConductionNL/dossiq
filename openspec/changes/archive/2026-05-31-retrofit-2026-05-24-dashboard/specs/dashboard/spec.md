---
retrofit_extensions:
  - REQ-DASH-016
  - REQ-DASH-017
  - REQ-DASH-018
---

# Dashboard — widget + controller surface (retrofit)

## Requirements

### REQ-DASH-016: The Nextcloud dashboard SHALL provide CasesOverview, MyTasks and StartCase widgets

`CasesOverviewWidget`, `MyTasksWidget` and `StartCaseWidget` SHALL each implement `OCP\Dashboard\IWidget` with stable kebab-case ids (`procest-cases-overview`, `procest-my-tasks`, `procest-start-case`), localised titles via `IL10N::t()`, deterministic `getOrder()` return values, MDI-style `getIconClass()`, and an in-app `getUrl()` deep link. Each widget's `load()` SHALL register the corresponding webpack-built Vue bundle via `Util::addScript()` + `Util::addStyle()` — no server-side data computation in PHP.

#### Scenario: StartCase widget exposes deep-link to the case-creation flow
- **WHEN** a user clicks the Start Case widget tile
- **THEN** the dashboard SHALL navigate to the URL returned by `StartCaseWidget::getUrl()` (which deep-links to `/cases/new`)

### REQ-DASH-017: DashboardController SHALL expose the in-app dashboard data endpoints

`OCA\Procest\Controller\DashboardController` SHALL serve the in-app dashboard surface (not the Nextcloud dashboard system, which is widget-driven and loads client-side). The controller SHALL provide endpoints for the dashboard landing-page Vue to fetch its initial state (KPIs, layout, widget data) so the SPA can render without spinning up multiple per-widget HTTP requests.

#### Scenario: Initial dashboard payload
- **WHEN** an authenticated user opens the in-app dashboard (`/dashboard`)
- **THEN** `DashboardController` SHALL respond with a single payload containing the user's saved layout plus the data each widget needs to render its first frame

### REQ-DASH-018: All three workflow widgets SHALL be registered at app boot via Application

`OCA\Procest\AppInfo\Application::register()` SHALL register `CasesOverviewWidget`, `MyTasksWidget`, and `StartCaseWidget` with the Nextcloud dashboard container so they appear in the dashboard widget picker. Registration order SHALL match the canonical `getOrder()` return values.

#### Scenario: Widgets appear in the dashboard picker
- **WHEN** an authenticated user opens the dashboard widget picker
- **THEN** all three workflow widgets SHALL be listed alongside the signalering widgets
