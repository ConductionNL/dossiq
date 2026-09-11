# Design: case-header

## Context

`CaseDetail` (`src/manifest.json`) is a `detail` page over `dossiq/case`.
nextcloud-vue 2.40.0's `CnDetailPage` reads `config.titleField` and
`config.subtitleField` (falling back to the schema's name and description
fields); it has no templated subtitle, no field chips and no `breadcrumbs`
key. The library ships `CnStatusBadge` (label, variant, size),
`CnBreadcrumbs` and a `header` banner widget (title, subtitle, cta), and
its `CnTabsWidget` reads `tabs[].widgetId` and `label` only, no `visibleIf`.

## D1: a custom row widget, not the `header` banner

The `header` widget is a dashboard banner with a call to action; it cannot
bind a status field or a countdown. `case-header` is therefore a `custom`
widget (`CaseHeaderRow.vue`) on the first layout row, the same slot pattern
as `initiator`. It reuses the countdown thresholds of the retired
`case-kpi-time-left` tile so the deadline reads the same as before.

## D2: the identifier is the interim subtitle

`subtitleField: identifier` is the one key the page reads today. The full
line (identifier, case type, assignee) is filed as a nextcloud-vue need in
placement section 3 and marked `[blocked]` in tasks.md.

## D3: order now, hide later

The tab order is pure config. Hiding an empty tab needs `visibleIf` on a
tab entry; until it lands the four conditional tabs sit last, which keeps
the six work tabs on one line at 1024 either way. Tab ids follow the
sibling changes so the order needs no rename when they land:
`case-documents`, `case-roles`, `case-communication`. No `case-timeline`
entry: `case-timeline` (row A05) keeps the case's one history in the
sidebar History tab, so the body strip carries no second view of it. Amended
by `case-timeline` task 4.1, both changes being unarchived in this batch.
