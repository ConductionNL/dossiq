# Design: My Work

## Architecture
- **Frontend**: `MyWork.vue` aggregating assigned cases and tasks
- **Grouping**: Items grouped by urgency (Overdue, Due This Week, Upcoming, No Deadline)
- **Sorting**: Priority then deadline within each group
- **Data**: OpenRegister queries filtered by current user's assignments

## Components
| Component | Path | Purpose |
|-----------|------|---------|
| `MyWork.vue` | `src/views/MyWork.vue` | My Work page |
| `MyWorkPreview.vue` | `src/views/dashboard/MyWorkPreview.vue` | Dashboard widget preview |
