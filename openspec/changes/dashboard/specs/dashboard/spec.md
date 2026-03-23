# Delta: dashboard

## Changes from base spec

### REQ-DASH-003 (IMPLEMENTED)
- Added Cases by Type horizontal bar chart widget to Dashboard.vue
- Aggregates open cases by case type name, sorted by count descending
- Click on bar navigates to Cases view filtered by type
- Uses same CSS bar chart pattern as Cases by Status

### Application widget registration (FIX)
- Registered CasesOverviewWidget, MyTasksWidget, OverdueCasesWidget in Application.php
- Fixed CasesOverviewWidget route from `.dashboard.index` to `.dashboard.page`
