## Why

Procest has a dashboard with KPI cards, status charts, and a My Work preview, but it lacks proactive alerting. Users must actively check the dashboard to discover overdue cases or approaching deadlines. Dutch government case management (zaakgericht werken) requires timely handling — the Awb mandates processing deadlines, and missing them has legal consequences. Competitors like Dimpact ZAC and Valtimo provide deadline warning systems. Procest needs signalering widgets that surface time-sensitive alerts so case handlers act before deadlines pass, not after.

## What Changes

- Add a **Deadline Alerts widget** to the Procest dashboard showing cases approaching their processing deadline (configurable warning threshold, e.g., 3 days) and cases already overdue, with severity indicators
- Add a **Task Due Reminders widget** showing the current user's tasks approaching or past their due date, sorted by urgency
- Add a **Stalled Cases widget** identifying cases that have not had any status change or activity for a configurable period (e.g., 7 days), indicating they may need attention
- Register corresponding **Nextcloud Dashboard widgets** (IWidget implementations) so alerts appear on the main Nextcloud dashboard, not just within Procest
- Add **dashboard helper functions** for computing deadline proximity, stalled case detection, and urgency sorting
- Extend the existing dashboard layout to include the new signalering widgets in a dedicated "Alerts" row

## Capabilities

### New Capabilities
- `signalering-widgets`: Dashboard widgets for deadline alerts, task due reminders, and stalled case detection — including both in-app dashboard components and Nextcloud-native IWidget registrations

### Modified Capabilities
- `dashboard`: Add signalering widget slots to the existing dashboard layout and extend the default grid layout configuration

## Impact

- **Frontend**: New Vue components in `src/views/dashboard/` and `src/views/widgets/`, new helper functions in `src/utils/dashboardHelpers.js`
- **Backend**: New PHP IWidget classes in `lib/Dashboard/` for Nextcloud-native widget registration
- **Config**: Updated `appinfo/info.xml` to register new dashboard widgets
- **Dependencies**: No new dependencies — uses existing OpenRegister API queries, Nextcloud OCP Dashboard API, and `@conduction/nextcloud-vue` CnDashboardPage
- **Pipelinq**: No direct impact — signalering operates on existing case/task data regardless of origin
