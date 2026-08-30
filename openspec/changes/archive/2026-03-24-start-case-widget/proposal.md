# Start Case Widget

## Summary

Add a "Start Case" Nextcloud Dashboard widget to Procest that lets users quickly create a new case directly from the Nextcloud dashboard or LaunchPad — without navigating into the Procest app first.

## Motivation

Case workers frequently need to start new cases throughout their day. Currently they must navigate to Procest, open the case list, and click "New case". A dashboard widget eliminates this friction by providing a one-click case creation flow directly from the Nextcloud home screen. This is the case management equivalent of a "quick compose" button — reducing the number of clicks from 3-4 to 1-2.

In government case management (zaakgericht werken), fast intake is critical. Citizens call or walk in, and the case worker needs to register a new case immediately. The widget should show the available case types as quick-start buttons and open the case creation dialog or navigate directly to the new case.

## Affected Projects
- [x] Project: `procest` — New StartCaseWidget (PHP + Vue)

## Scope

### In Scope

- **StartCaseWidget PHP class**: Implements `OCP\Dashboard\IWidget`, registered in Application.php
- **StartCaseWidget Vue component**: Renders in the Nextcloud dashboard panel
- **Case type quick-start buttons**: Shows configured case types (zaaktypen) as clickable buttons/cards with icons and names
- **Inline case creation**: Minimal form within the widget (case type selection + optional title) that creates the case via OpenRegister API and navigates to the new case detail page
- **Empty state**: When no case types are configured, show a helpful message directing admins to Procest settings
- **Webpack entry point**: Separate `startCaseWidget.js` entry compiled to `procest-startCaseWidget.js`
- **CSS**: Widget-specific styles in `dashboardWidgets.css` or scoped styles
- **i18n**: Dutch + English translations for all widget text

### Out of Scope

- Full case creation form with all fields (that stays in the Procest app)
- Case type management/configuration (existing admin settings)
- Workflow engine integration (cases start in default status per case type)

## Approach

The widget follows the established Procest/Pipelinq dashboard widget pattern:

1. **PHP**: `lib/Dashboard/StartCaseWidget.php` implementing `IWidget` with `IButtonWidget` for a "View all case types" button linking to Procest
2. **Vue**: `src/views/widgets/StartCaseWidget.vue` that fetches case types from OpenRegister and renders them as a grid of clickable cards
3. **Entry**: `src/startCaseWidget.js` registers via `OCA.Dashboard.register()`
4. **Webpack**: Add entry to `webpack.config.js`
5. **Registration**: Add `$context->registerDashboardWidget(StartCaseWidget::class)` in Application.php

When a user clicks a case type, the widget either:
- (a) Opens a minimal inline form (title + description) and creates the case, then navigates to `apps/procest/cases/{newId}`, or
- (b) Navigates directly to `apps/procest/cases/new?type={caseTypeId}` to use the full creation dialog

Option (a) is preferred for speed; option (b) is the safe fallback.

## Cross-Project Dependencies

- **OpenRegister**: Case types and cases stored as OpenRegister objects (existing)
- **LaunchPad** (optional): Widget appears automatically when LaunchPad discovers registered Nextcloud widgets

## Rollback Strategy

Remove the widget class from Application.php registration and delete 3 new files (PHP class, Vue component, JS entry point).

## Acceptance Criteria

1. GIVEN a user viewing the Nextcloud Dashboard, WHEN they add the "Start Case" widget, THEN they see a list of available case types from Procest
2. GIVEN a user with the Start Case widget on their dashboard, WHEN they click a case type, THEN a new case of that type is created (with optional title input) and they are navigated to the case detail page in Procest
3. GIVEN no case types configured in Procest, WHEN the widget loads, THEN it shows an empty state with a message to configure case types in Procest admin settings
4. GIVEN a user who does not have access to Procest, WHEN they view the dashboard, THEN the widget is not shown (or shows appropriate access message)
5. GIVEN the widget is loaded, WHEN case types are fetched, THEN icons and names are displayed in both Dutch and English based on user locale
