# Design: dashboard

## Changes

### Dashboard.vue
- Add Cases by Type widget (aggregates open cases by case type, sorted by count descending)
- Add typeData state, typeBarWidth method, widget definition, and layout entry
- Click on a bar navigates to Cases view filtered by case type

### Application.php
- Register dashboard widgets via `$context->registerDashboardWidget()`

### CasesOverviewWidget.php
- Fix route from `.dashboard.index` to `.dashboard.page`
