# Proposal: dashboard

## Summary

Add Cases by Type chart (V1) to the dashboard, register Nextcloud dashboard widgets in Application.php, and fix CasesOverviewWidget route.

## Scope

### In Scope
- **REQ-DASH-003**: Cases by Type horizontal bar chart with click-to-filter navigation
- Register dashboard widgets (CasesOverviewWidget, MyTasksWidget, OverdueCasesWidget) in Application.php
- Fix CasesOverviewWidget route from non-existent `.dashboard.index` to `.dashboard.page`

### Out of Scope
- SLA compliance widget (V1)
- Workload distribution (V1)
