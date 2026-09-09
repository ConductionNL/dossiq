# Design: case-timeline

## Context

`CaseDetail` (`src/manifest.json`, `pages[4]`) declares
`config.sidebar.tabs` with six entries: `audit` (History, `widgets:
[{ type: "audit" }]`, resolved by `CnObjectSidebar` to `CnAuditTrailTab`),
`version-history` (`component: VersionHistoryLeafTab`, `leafTab('version-history')`
in `src/registry.js`), `notes`, `sharing`, `email` and `besluitvorming`.
`CnAuditTrailTab` fetches `/audit-trails` for the page object, renders
Action and User filters and a Load more, and keeps `actionFilter` as
internal state; the built-in `audit-trail` widget schema exposes
`register`, `schema`, `objectId`, `title` and `maxDisplay` only. Neither
tab exports.

## D1: the sidebar tab is the timeline

Row A05 asks for one sidebar tab. The `audit` tab already is one, with the
filters the competitors have; the `version-history` tab is the same audit
rows as a diff and is what makes the baseline count two audit views. So
the config change is a removal on `CaseDetail`, not an addition. The
registry entry and the 13 other pages' tabs stay: `ncvue-w2-leaves-adoption`
put them there and A05 scores the case page only.

## D2: no body tab, amend case-header instead

`case-header` names `case-timeline` in the body strip. A body `audit-trail`
card (`maxDisplay`, no filters) beside a sidebar tab with filters is two
views of one log again. The strip loses the entry; the sidebar keeps the
tab. This is an edit to a sibling change in the same batch (task 4.1),
which is cheaper and more honest than a `tabs` child rendering the same
widget twice.

## D3: filter preset, export and the merged feed are needs, not code

Kind is config. A preset of `actionFilter` to writes and an Export action
are props and a button on `CnAuditTrailTab`, filed against nextcloud-vue.
Document, note and mail events on the same list are the OpenRegister
`activity` leaf. Each carries a `[blocked]` task and an interim; nothing in
dossiq fakes them.

## Test seams

- `tests/vitest/manifestCaseTimeline.spec.js`: the `CaseDetail` sidebar
  has exactly one tab whose widget type is `audit` and none with id
  `version-history` or component `VersionHistoryLeafTab`; every other
  detail page's tab count is unchanged.
- `tests/e2e/case-timeline.spec.ts`: seeds a case, performs one update,
  opens the sidebar, asserts the tab set by id, the newest row on top
  with actor and action, and the Action filter narrowing to update.
