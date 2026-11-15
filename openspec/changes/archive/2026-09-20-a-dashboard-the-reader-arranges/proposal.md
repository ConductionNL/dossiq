---
kind: code
depends_on: []
---

# Proposal: a-dashboard-the-reader-arranges

Gap scan pack c, parity ledger rows 10.1 "Configurable KPI dashboards" and
10.10 "Widgets driven by a saved search". The consumer half of nextcloud-vue
`dashboard-layout-per-user`, merged as ConductionNL/nextcloud-vue#1209 onto
`parity/round2`.

## Why

Five dashboards ship in this app and every one of them is the same page for
everybody. A team lead watching throughput and a handler watching their own
deadlines get the identical grid, and the only way either of them changes it
is to ask an administrator to edit the manifest.

All three competitors in the round 2 corpus let a user own their landing
page. We shipped the library half a day ago and have not placed it.

## What is actually there

Read against `parity/round2` at `c3bdf65d`.

The widget types are not the problem. The ledger's note for row 10.1 says
the five `stat` KPIs render "Widget not available"; that is stale. The
library's catalogue carries `stat`, `delta`, `gauge`, `stats-block` and
`chart`, and this app's `Dashboard` page declares five `stat` widgets whose
`endpointSource` is `/apps/dossiq/api/dashboard/kpis`, a route that exists
in `appinfo/routes.php` and is served by `KpiController`.

What is missing is one manifest key. `CnDashboardPage` reads
`config.userLayout`, and a page without it makes no layout request and
renders exactly as it does today. `git grep userLayout src/manifest.json`
returns nothing, on any of the five dashboards.

## What this change does

- **A reader arranges the dashboard they read.** `Dashboard`, `MyWorkHome`,
  `Doorlooptijd`, `ProcessMiningDashboard` and `TermijnDashboard` declare
  `userLayout: true`. Geometry is the user's, membership stays the
  administrator's: a widget an administrator removes disappears for
  everybody, stored arrangement or not.
- **The two lists a handler actually wants are offered by name.** The
  `Dashboard` page declares `userWidgets`, the preset list `CnAddWidgetModal`
  reads, with the queries a handler cannot be expected to write: the cases
  they are following, and the cases assigned to their team.
- **Nothing is said when a layout write fails.** An instance with no
  preference route renders the manifest page, which is the right answer, and
  a toast on every failed write is noise about something the reader did not
  ask for.

## What this change does not do

It does not let a user add a widget over a register and schema they choose.
`listUserAddableWidgetTypes()` is deliberately a subset: an administrator
configures a widget with the register and the schema in front of them, and a
user has neither, so offering the administrator's catalogue offers types a
user cannot finish configuring and the failure arrives as a blank widget.

It does not close row 10.10. A widget driven by one of the user's own saved
views needs a widget type that reads a saved view, and the library has none:
`userWidgets` presets are configured by this app, not by the reader. That
half is ConductionNL/nextcloud-vue `a-saved-view-drives-a-widget`, and the
placement here waits on it.

## Impact

- `src/manifest.json`, the five dashboard pages
- Affected specs: `dashboard`
- Size: S
