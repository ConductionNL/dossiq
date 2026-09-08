# Tasks: case-header

Tier: V1. Kind: config. Every checkbox below is one implementation task; the
criteria under a task are plain bullets. Depends on `documents-on-the-case`
(`case-documents`), `parties-on-the-case` (`case-roles`) and, in batch 2,
`contact-moments` (`case-communication`) for the tab ids it orders.

## 1. The subtitle

- [x] 1.1 `src/manifest.json` page `CaseDetail`: set `config.subtitleField`
  to `identifier`; a `_note` naming placement row A01 and the interim.
  - `@spec openspec/changes/case-header/specs/case-dashboard-view/spec.md`
  - `npm run check:manifest` exits 0
  - unit test in `tests/vitest/manifestCaseHeader.spec.js`: `subtitleField`
    equals `identifier`; `menu` and the page count unchanged
- [ ] 1.2 [blocked: nextcloud-vue `CnDetailPage` a templated `subtitle`
  (identifier, case type, assignee) or field chips in the detail header,
  placement section 3 row A01] Replace 1.1 with the full line once it
  lands; until then this task stays open and the identifier is the subtitle.

## 2. The header row

- [ ] 2.1 `src/manifest.json` page `CaseDetail`: add widget `case-header`
  (`type: custom`, `component: CaseHeaderRow`, `props.objectId: @objectId`)
  and put it on layout row 0 at `gridX 0, gridWidth 8, gridHeight 2,
  showTitle false`; remove `case-kpi-time-left` from `widgets` and `layout`;
  shift `case-kpi-casetype` to `gridX 8`; drop `case-kpi-progress` only if
  `case-lifecycle-on-the-page` has not already done so.
  - `@spec openspec/changes/case-header/specs/case-dashboard-view/spec.md`
  - `npm run check:manifest` exits 0
  - unit test in `tests/vitest/manifestCaseHeader.spec.js`: `case-header`
    is on row 0, no widget id `case-kpi-time-left` remains anywhere on the
    page, every layout `widgetId` resolves to a widget
- [ ] 2.2 `src/components/CaseHeaderRow.vue`: reads the case from the page
  object store by `objectId`, resolves `status` to its status type title
  through the shared object store (register `dossiq`, schema `statusType`,
  `labelField: title`), renders `CnStatusBadge` (variant `success` for a
  final status, `info` otherwise, label Unknown when no status record) and
  the countdown from `deadline` with the `warn 14, danger 5` thresholds
  `case-kpi-time-left` used, with `data-testid="case-header-status"` and
  `data-testid="case-header-countdown"`; renders no countdown when
  `deadline` is empty. Register it in `src/customComponents.js` and add the
  slot `widget-case-header: CaseHeaderRow` on the page.
  - `@spec openspec/changes/case-header/specs/case-dashboard-view/spec.md`
  - unit test in `tests/vitest/CaseHeaderRow.spec.js`: badge label from the
    resolved status type, Unknown on null, 26 days overdue with the danger
    variant, 56 days left plain, no countdown element on a null deadline
  - `npm run lint` exits 0

## 3. The breadcrumb

- [ ] 3.1 `src/manifest.json` page `CaseDetail`: add `breadcrumbs:
  [{ label: "Cases", route: "Cases" }, { field: "title" }]`. If the 2.40.0
  page schema rejects `breadcrumbs`, render `CnBreadcrumbs` at the top of
  `CaseHeaderRow.vue` instead and note the block in the widget `_note`.
  - `@spec openspec/changes/case-header/specs/case-dashboard-view/spec.md`
  - `npm run check:manifest` exits 0
  - unit test in `tests/vitest/manifestCaseHeader.spec.js`: two crumbs, the
    first routes to `Cases`, the last carries no route
- [ ] 3.2 [blocked: nextcloud-vue `CnDetailPage` reading a `breadcrumbs`
  key from the manifest] Move the crumbs out of `CaseHeaderRow.vue` into the
  page config once the key exists; until then this task stays open.

## 4. The tab order

- [ ] 4.1 `src/manifest.json` page `CaseDetail` `case-panels.content.tabs`:
  order Data (`case-core`, moved into the strip and out of `layout`),
  Documents (`case-documents`), Parties (`case-roles`), Tasks
  (`case-tasks`), Communication (`case-communication`), Timeline
  (`case-timeline`), then Files, Notes, Mail, Related cases and Contacts
  while their folding changes are open, then Sub-cases (`case-sub-cases`),
  Locations (`case-locaties`), Appointments (`case-calendar`), Decisions
  (`case-decidesk-decisions`). Leave out any entry whose widget id is not on
  the page yet; re-run this task when a sibling lands. Grow `case-panels`
  to `gridHeight 14` to take the Data tab's rows.
  - `@spec openspec/changes/case-header/specs/case-dashboard-view/spec.md`
  - `npm run check:manifest` exits 0
  - unit test in `tests/vitest/manifestCaseHeader.spec.js`: every tab
    `widgetId` exists in `widgets`, none of them appears in `layout`, the
    present members of the six work tabs keep their relative order, and the
    four conditional tabs are the last four
- [ ] 4.2 [blocked: nextcloud-vue `CnTabsWidget` `visibleIf` on a tab entry,
  placement section 3 row A33] Add `visibleIf: { count: "> 0" }` (or the
  shape that lands) to Sub-cases, Locations, Appointments and Decisions;
  until then they stay last and this task stays open.
- [ ] 4.3 Update `tests/e2e/case-detail-kpis-and-tabs.spec.ts`: the Time
  left tile is gone, the tab list assertion reads the new order. Grep
  `tests/e2e` for `Time left`, `Notes`, `Files` and `Related cases` first.

## 5. Verification

- [ ] 5.1 `tests/e2e/case-header.spec.ts`: seeds one case with identifier
  2026-0015, status In behandeling, a deadline 26 days back and three
  tasks, plus one case without status or deadline; asserts the subtitle,
  `case-header-status`, `case-header-countdown` with class `is-danger`, the
  breadcrumb (`aria-current="page"` on the last crumb, Cases navigates and
  keeps `?lens=`), the tab order by `data-testid` of the tab buttons, and at
  viewport 1024x768 that the first six tab buttons share one `offsetTop`
  and the strip's `getBoundingClientRect().top` is under 768. Assert ids
  and testids, not labels, because the instance may run in Dutch.
  - the spec must appear in `tests/e2e/playwright.config.ts`'s project, the
    config CI reads
- [ ] 5.2 Run `npm run lint`, `npm run check:manifest`, `npm run test:unit`
  and the e2e spec locally; read `$?` on each, not the summary line.
- [ ] 5.3 `docs/case-detail.md` (or the page's docs entry): one paragraph
  on the header row, the breadcrumb and the tab order; load the `writing`
  skill first.
