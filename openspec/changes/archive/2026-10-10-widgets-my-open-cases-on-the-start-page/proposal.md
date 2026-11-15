---
kind: code
depends_on: []
---

# Proposal: widgets-my-open-cases-on-the-start-page

OpenSpec pass of 2026-09-27. One row in the launchpad matrix
(`ConductionNL/launchpad`, `openspec/parity/capabilities.json`) whose
`built.owner` is `ConductionNL/dossiq`.

| matrix | row | capability | own rating | built.state |
| --- | --- | --- | --- | --- |
| launchpad | `d-work-queue` | See your own open cases and work items on the start page and click through to the work queue. | partial | built |

## Why

A handler who starts the day on a start page should see their own open cases
and work items there, and one click should take them to My work. dossiq
registers seven Nextcloud dashboard widgets (`lib/AppInfo/Registrar/AppHostRegistrar.php`),
and every one is a legacy `IWidget` that renders by mounting dossiq's own Vue
bundle through `OCA.Dashboard.register` (`src/casesOverviewWidget.js`,
`src/myTasksWidget.js`). A host that reads widget items instead, such as the
Nextcloud dashboard API, the mobile apps or a start page built on them, gets
nothing to show.

The launchpad matrix note: "LaunchPad has no work queue of its own; it can show
a case app's Nextcloud widget. dossiq registers 'My tasks' and 'Cases overview'
widgets ... (lib/Dashboard/MyTasksWidget.php:42 a legacy IWidget) and
LaunchPad's bridge catches OCA.Dashboard.register for such widgets
(widgetBridge.js:29-62). Whether they render end to end is pending
q-sibling-widgets."

Demand: a tender row. Origin
https://www.tenderned.nl/aankondigingen/overzicht/296963 (Noorderzijlvest 2023:
"Startscherm/medewerkersportaal met doorklik naar werkvoorraad"); also mined from
https://www.tenderned.nl/aankondigingen/overzicht/237144 (Nationale ombudsman
2021: "persoonlijk dashboard ... Overzicht lopende (eigen) zaken") and
https://www.tenderned.nl/aankondigingen/overzicht/416075 (Súdwest-Fryslân 2026).

Two competitors rate it yes, quoted from the matrix:

- Workspace 365, https://support.workspace365.net/en/articles/175605-zaaksysteem:
  the live tile shows "My open cases" and "My suspended cases" with counts;
  "Click on Zaaksysteem at the bottom of the live tile to navigate to
  Zaaksysteem itself".
- Microsoft Viva, https://learn.microsoft.com/en-us/sharepoint/homesites/available-dashboard-cards:
  the Assigned tasks card lets users "create and view tasks from the card or
  open the Planner app".

## What changes

- A widget "My open work" lists the signed-in person's open cases and work
  items from the one personal queue, soonest due first, each linking to its
  case or task.
- It answers through Nextcloud's widget item API, so any host that reads items
  renders it without dossiq's JavaScript.
- A button "Open my work" leads to the My work page, and the widget says how
  many more items there are than it shows.
- "My tasks" gains the same item API, so the two existing widgets a start page
  is most likely to hold render everywhere.

## What this change does not do

- It does not change the Nextcloud dashboard rendering of the existing widgets;
  their `load()` path stays.
- It does not change widget ids. They are frozen at the old app-id prefix,
  because the Dashboard app stores each user's chosen widgets by id.
- It does not add a work queue to launchpad.

## Sibling halves

- **ConductionNL/launchpad**: nothing new. Its bridge already mounts callback
  widgets, and its Nextcloud widget component reads items where a widget has
  no callback. Its row moves to built once this lands.

## Capabilities

- Modified: `dashboard`: two requirements added.

## Impact

One new class under `lib/Dashboard`, `MyTasksWidget` gains two interfaces, one
registration line in `AppHostRegistrar`. No new route: items are served by
Nextcloud's `/ocs/v2.php/apps/dashboard/api/v2/widget-items`.
