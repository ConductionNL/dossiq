# Design: Task Management

## Architecture
- **Data model**: Task entity as CMMN HumanTask, Schema.org Action typing
- **Lifecycle**: Available -> Active -> Completed (CMMN PlanItem lifecycle)
- **Frontend**: `TaskList.vue`, `TaskDetail.vue`, `TaskCreateDialog.vue`
- **Store**: OpenRegister objects via Pinia store

## Components
| Component | Path | Purpose |
|-----------|------|---------|
| `TaskList.vue` | `src/views/tasks/TaskList.vue` | Task list with filtering |
| `TaskDetail.vue` | `src/views/tasks/TaskDetail.vue` | Task detail/edit |
| `TaskCreateDialog.vue` | `src/views/tasks/TaskCreateDialog.vue` | Task creation dialog |

## Utilities
- `src/utils/taskLifecycle.js` — CMMN task state transitions
- `src/utils/taskHelpers.js` — Task utility functions
- `src/services/taskApi.js` — Task API service
