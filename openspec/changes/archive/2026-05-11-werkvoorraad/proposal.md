## Why

The werkvoorraad (team work queue) is required by 23% of tenders and is implicit in the 86% requiring user management. Currently only the personal My Work view exists. Team leads need oversight of unassigned cases, workload distribution, and the ability to assign/reassign work items across their team.

## What Changes

- **REQ-WV-01**: New Werkvoorraad view with team overview, unassigned queue, and per-member breakdown
- **REQ-WV-03**: Urgency-based sorting (overdue first, then by deadline proximity)
- **REQ-WV-04**: Filter tabs by case type and status
- **REQ-WV-07**: KPI cards at top (open cases, overdue, completed this week)
- Navigation entry in sidebar for Werkvoorraad
- Route `/werkvoorraad` added to router

## Capabilities

### New Capabilities
- `werkvoorraad-view`: Team work queue with case listing, KPIs, filters, and urgency sorting

### Modified Capabilities
- `navigation`: Add Werkvoorraad menu item
- `routing`: Add `/werkvoorraad` route

## Impact

- **Frontend**: New `src/views/Werkvoorraad.vue` component
- **Frontend**: `src/navigation/MainMenu.vue` — add Werkvoorraad nav item
- **Frontend**: `src/router/index.js` — add route
- **No backend changes** (queries via existing objectStore)
