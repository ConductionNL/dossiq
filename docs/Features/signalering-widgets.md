# Signalering Widgets

**Issue:** #88
**Branch:** `feature/88/signalering-widgets`

## Overview

The signalering (alerting) feature adds six Nextcloud Dashboard widgets that give caseworkers a real-time overview of cases requiring attention.

## Widgets

| Widget | ID | Purpose |
|--------|-----|---------|
| Cases Overview | `procest_cases_overview_widget` | List of recent open cases |
| Deadline Alerts | `procest_deadline_alerts_widget` | Cases approaching their processing deadline |
| Overdue Cases | `procest_overdue_cases_widget` | Cases that have exceeded their deadline |
| Stalled Cases | `procest_stalled_cases_widget` | Cases with no recent activity |
| Task Reminders | `procest_task_reminders_widget` | Upcoming task deadlines |
| My Tasks | `procest_my_tasks_widget` | Tasks assigned to the logged-in user |

## Architecture

All widgets are implemented as PHP classes in `lib/Dashboard/` implementing `OCP\Dashboard\IWidget`. They load Vue components via `Util::addScript()` and share a single stylesheet (`dashboardWidgets`).

The Vue components fetch data at runtime from the OpenRegister backend using the Dossiq Pinia store. No data is stored in the PHP layer; widgets are purely metadata wrappers that bootstrap the frontend component.

## Testing

Unit tests are in `tests/Unit/Dashboard/SignaleringWidgetsTest.php`. They verify:
- Each widget returns its correct unique ID
- All widget IDs are unique across the set
- All widgets return non-empty titles (translated via IL10N)
- All widgets provide a URL back to the Dossiq dashboard route
- Widget display order is consistent (Deadline > Cases)
