# Signalering Widgets — Design & Implementation

**Status**: In Development
**Issue**: #213
**Change**: signalering-widgets
**Spec**: openspec/specs/signalering-widgets/spec.md

## What Must Be Built

V1 implementation of the Signalering Widgets feature — dashboard widgets for case deadline alerts, task due reminders, and stalled case detection.

The feature provides three Nextcloud-native dashboard widgets that surface time-sensitive alerts:
1. **Deadline Alerts Widget** — Shows cases approaching or past processing deadlines, sorted by urgency with color-coded severity (red/overdue, orange/at-risk)
2. **Task Due Reminders Widget** — Shows the current user's tasks approaching or past due dates
3. **Stalled Cases Widget** — Identifies cases with no activity for a configurable period (default: 7 days)

All computation is client-side from already-loaded case/task data; no new API calls are required.

## Why

480+ tender requirements across Signalering (280) and Termijnbewaking (151) demand proactive deadline awareness. Dashboard widgets deliver the most-requested feature (requirement #4: "users view upcoming deadlines on dashboard") without new data infrastructure — the workflow engine provides deadline data, this layer surfaces it.

## Implementation Scope

### Included in V1

- **Helper functions** (`src/utils/dashboardHelpers.js`):
  - `getDeadlineAlerts(cases, caseTypes, warningThreshold)` — returns `{overdue, atRisk}` grouped arrays
  - `getTaskDueReminders(tasks, warningThreshold)` — returns `{overdue, dueSoon}` grouped arrays
  - `getStalledCases(cases, caseTypes, stalledThreshold)` — returns array of inactive cases
  
- **Dashboard integration** (`src/views/dashboard/`):
  - `DeadlineAlerts.vue` — Dashboard widget component
  - `TaskDueReminders.vue` — Dashboard widget component
  - `StalledCases.vue` — Dashboard widget component
  - Widget registration in dashboard layout (DEFAULT_LAYOUT row 3)
  
- **Nextcloud Dashboard IWidget registration** (`lib/Dashboard/`):
  - `DeadlineAlertsWidget.php` — Nextcloud widget adapter
  - `TaskRemindersWidget.php` — Nextcloud widget adapter
  - `StalledCasesWidget.php` — Nextcloud widget adapter
  - Webpack entry points: `src/deadlineAlertsWidget.js`, `src/taskRemindersWidget.js`, `src/stalledCasesWidget.js`
  - Widget Vue components: `src/views/widgets/DeadlineAlertsWidget.vue`, `src/views/widgets/TaskRemindersWidget.vue`, `src/views/widgets/StalledCasesWidget.vue`

- **Configuration**:
  - `DEADLINE_WARNING_DAYS = 3` (default threshold for deadline alerts)
  - `STALLED_THRESHOLD_DAYS = 7` (default threshold for stalled case detection)
  - Thresholds defined as named constants for future configurability

### Out of Scope (Separate Changes)

- Configurable per-zaaktype alert thresholds and channels (requires admin settings architecture)
- In-app notifications (INotificationManager integration)
- Email notifications via n8n
- Werkvoorraad and case detail signalering indicators
- Bulk deadline overview management view

## Key Decisions

1. **Client-side computation** — Thresholds applied during dashboard render, not server-side. No new API, no indexing burden.
2. **Reusable helper functions** — Dashboard views and Nextcloud widgets both consume the same computation logic, reducing duplication.
3. **Nextcloud Dashboard integration via IWidget** — Native dashboard registration allows users to add/remove widgets from the picker, respect their preferences.
4. **Open case filtering** — Only non-final-status cases shown; completed cases are not "stalled".
5. **Per-user task list** — Task Due Reminders filters to `assignee === currentUser`, not all team tasks.
6. **Severity indicators** — Both color and text (red="overdue", orange="at-risk", yellow="due soon") for colorblind accessibility.

## Testing Strategy

- **Unit tests** on helper functions: edge cases (no deadline, deadline today, overdue cases, final-status cases)
- **Component tests** on Vue widgets: rendering, click navigation, empty state messages
- **E2E smoke test** (if dashboard E2E suite exists): login → add widget → verify top N items shown

No new database schema; all data from existing `case` and `task` entities with existing `deadline` and `dateModified` fields.
