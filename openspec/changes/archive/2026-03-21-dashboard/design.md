# Design: Dashboard

## Architecture
- **Frontend**: `Dashboard.vue` as landing page with KPI cards, charts, panels
- **Data sources**: OpenRegister queries for cases/tasks, Nextcloud Activity API
- **Pattern**: Component-based dashboard with independent data fetching per panel

## Components
| Component | Path | Purpose |
|-----------|------|---------|
| `Dashboard.vue` | `src/views/Dashboard.vue` | Dashboard page shell |
| `KpiCards.vue` | `src/views/dashboard/KpiCards.vue` | Open cases, overdue, completed, my tasks |
| `StatusChart.vue` | `src/views/dashboard/StatusChart.vue` | Cases by status distribution |
| `OverduePanel.vue` | `src/views/dashboard/OverduePanel.vue` | Overdue cases panel |
| `MyWorkPreview.vue` | `src/views/dashboard/MyWorkPreview.vue` | Personal workload preview |
| `ActivityFeed.vue` | `src/views/dashboard/ActivityFeed.vue` | Recent activity feed |

## Data Flow
1. Dashboard loads -> fetches cases, tasks, statuses from OpenRegister
2. KPI cards compute counts from case/task collections
3. Status chart derives distribution from case statuses
4. Overdue panel filters cases where deadline < today
5. My Work preview shows user's assigned items
6. Activity feed queries Nextcloud Activity API

## Helpers
- `src/utils/dashboardHelpers.js` — dashboard computation utilities
