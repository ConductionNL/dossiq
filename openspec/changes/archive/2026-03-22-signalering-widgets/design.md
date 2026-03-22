## Context

Procest has a functional dashboard with KPI cards, a status chart, and a My Work preview. The dashboard loads case and task data via `Promise.allSettled` from the OpenRegister API and computes metrics via helper functions in `src/utils/dashboardHelpers.js`. Three Nextcloud IWidget implementations exist in `lib/Dashboard/` for native dashboard integration.

The existing `isCaseOverdue` and `getDaysRemaining` functions in `src/utils/caseHelpers.js` already handle deadline math. The dashboard uses `CnDashboardPage` from `@conduction/nextcloud-vue` with a configurable grid layout.

This change adds three signalering (alerting) widgets: Deadline Alerts, Task Due Reminders, and Stalled Cases. All data comes from the same OpenRegister collections already fetched by the dashboard.

## Goals / Non-Goals

**Goals:**
- Surface time-sensitive alerts proactively on the Procest dashboard
- Provide Nextcloud-native widgets so alerts are visible from the main Nextcloud dashboard
- Reuse existing data fetching (no additional API calls) — compute alerts from already-loaded cases/tasks
- Follow existing patterns (widget components, helper functions, IWidget PHP classes)

**Non-Goals:**
- Push notifications or email alerts (separate feature, see FEATURES.md: "Notifications" V1)
- Configurable thresholds via admin UI (hardcoded defaults with constants for now)
- Backend-computed alerts or server-side caching (all computation is client-side)
- Alert history or acknowledgment tracking

## Decisions

### 1. Client-side computation from existing data

**Decision**: Compute alerts from the case/task arrays already fetched by `loadDashboardData()` rather than adding new API endpoints.

**Rationale**: The dashboard already fetches all cases (limit 1000), case types, status types, and user tasks. Deadline proximity and stalled detection are simple date comparisons. Adding backend endpoints would introduce complexity without benefit since the data is already available.

**Alternative considered**: Backend PHP service with filtered API endpoints. Rejected because Procest follows the thin-client pattern (no own database tables, OpenRegister API for everything).

### 2. Three separate widgets instead of one unified alert panel

**Decision**: Create three distinct widgets (Deadline Alerts, Task Due Reminders, Stalled Cases) instead of one combined "Alerts" panel.

**Rationale**: Separate widgets allow users to rearrange, resize, or hide individual alert types via the CnDashboardPage grid. They also map cleanly to separate Nextcloud IWidget implementations for the native dashboard.

**Alternative considered**: Single "Signalering" widget with tabs. Rejected because it would prevent per-category visibility control and doesn't align with the existing widget-per-concern pattern.

### 3. Configurable thresholds as constants

**Decision**: Define `DEADLINE_WARNING_DAYS = 3` and `STALLED_THRESHOLD_DAYS = 7` as constants in `dashboardHelpers.js`. Pass them as parameters to helper functions.

**Rationale**: Keeps the initial implementation simple while allowing easy extraction to admin settings later. The helper functions accept threshold parameters so tests and future configurability are straightforward.

**Alternative considered**: Reading from Nextcloud IAppConfig. Deferred to a follow-up change since it requires backend endpoint changes.

### 4. Helper functions in existing dashboardHelpers.js

**Decision**: Add `getDeadlineAlerts()`, `getTaskDueReminders()`, and `getStalledCases()` to the existing `src/utils/dashboardHelpers.js` file.

**Rationale**: Follows the established pattern — all dashboard computation helpers live in one file. The new functions have the same shape (take arrays, return display-ready objects).

### 5. Stalled detection via dateModified field

**Decision**: Use the `dateModified` field on case objects to determine inactivity. If absent, fall back to `dateCreated`.

**Rationale**: OpenRegister automatically maintains `dateModified` on every object update. This captures status changes, field edits, and any mutation — which is a broader signal than checking only status change timestamps.

### 6. Widget Vue components follow existing pattern

**Decision**: Create `src/views/dashboard/DeadlineAlerts.vue`, `src/views/dashboard/TaskDueReminders.vue`, `src/views/dashboard/StalledCases.vue` as dashboard sub-components. Create `src/views/widgets/DeadlineAlertsWidget.vue`, `src/views/widgets/TaskRemindersWidget.vue`, `src/views/widgets/StalledCasesWidget.vue` for Nextcloud native widgets.

**Rationale**: Mirrors the existing split between `src/views/dashboard/` (Procest dashboard components) and `src/views/widgets/` (Nextcloud dashboard widget wrappers).

## Risks / Trade-offs

- **[Performance with many cases]** Filtering 1000 cases for deadline proximity adds O(n) iteration per widget load. Mitigation: These are simple date comparisons, negligible cost. The existing `computeKpis` already iterates the same arrays.

- **[Stale dateModified data]** If OpenRegister doesn't update `dateModified` on all mutations (e.g., relationship changes), some active cases might appear stalled. Mitigation: Document the dependency; if needed, add activity-based detection in a follow-up.

- **[Threshold not configurable by admin]** Users cannot change the 3-day and 7-day thresholds without code changes. Mitigation: Constants are centralized and well-named; admin settings can be added as a V2 enhancement.

## Open Questions

- Should the stalled threshold differ per case type (e.g., urgent cases stall after 3 days, standard after 7)?  For now, use a single global threshold.
