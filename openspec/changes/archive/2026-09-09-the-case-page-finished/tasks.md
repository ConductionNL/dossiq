# Tasks: the-case-page-finished

Tier: V1. Kind: config. Phase 3 change DQ2. Depends on `case-header` for the
tab ids it reorders, and on `documents-on-the-case`, `parties-on-the-case`
and `contact-moments` for the widgets it folds.

## 1. The container that lets one tab hold two panels

- [x] 1.1 `src/components/case/CaseSectionsWidget.vue`: a widget that renders
  `content.sections[]` stacked, each through `CnDetailWidgetHost` with
  `chrome="bare"`, under its own heading and behind a `data-testid`.
  - `@spec openspec/changes/the-case-page-finished/specs/case-dashboard-view/spec.md`
  - `npm run lint` and `npm run format` exit 0
- [x] 1.2 `src/components/case/registerCaseSections.js`: register the type in
  the SHARED catalog with `container: true`, and call it from `src/main.js`.
  - `@spec openspec/changes/the-case-page-finished/specs/case-dashboard-view/spec.md`
  - it goes in the shared catalog and not `registry.js` because
    `CnDetailWidgetHost.isContainer` reads `getWidgetTypeEntry()`, and only a
    container is handed `schemaObject`, `integrationContext` and
    `cnRegistry`. In `registry.js` the widget renders and its sections do not.
  - guarded in `tests/vitest/caseTabConsolidation.spec.js`

## 2. Fourteen tabs to six

- [x] 2.1 `src/manifest.json` page `CaseDetail`: five `case-sections` group
  widgets, each holding two of the panels that used to be tabs.
  - `@spec openspec/changes/the-case-page-finished/specs/case-dashboard-view/spec.md`
  - every child definition moves VERBATIM, so the diff is a move
  - `npm run check:manifest` exits 0
- [x] 2.2 Rewrite `case-panels.content.tabs` to the six entries.
  - `@spec openspec/changes/the-case-page-finished/specs/case-dashboard-view/spec.md`
  - exact count and exact order asserted in
    `tests/vitest/caseTabConsolidation.spec.js` and in
    `tests/e2e/case-detail-kpis-and-tabs.spec.ts`
- [x] 2.3 Delete the `case-notes`, `case-email` and
  `case-decidesk-decisions` widgets: each duplicates a sidebar tab.
  - `@spec openspec/changes/the-case-page-finished/specs/case-dashboard-view/spec.md`
  - the guard pairs each removed id with the sidebar tab that carries it, so
    removing one without the other reddens
- [x] 2.4 Four new labels in `l10n/en.json` and `l10n/nl.json`, and the
  generated `.js` beside them.
  - `@spec openspec/changes/the-case-page-finished/specs/case-dashboard-view/spec.md`
  - `npm run test:l10n` exits 0

## 3. The tests that were reading a fourteen-tab strip

- [x] 3.1 `tests/e2e/helpers/case-panels.ts` and
  `tests/vitest/helpers/casePanels.js`: one tab-to-section mapping each, so
  the next fold moves a table rather than every spec.
  - `@spec openspec/changes/the-case-page-finished/specs/case-dashboard-view/spec.md`
- [x] 3.2 Repoint eleven e2e specs and seven vitest specs, scoping each
  assertion to its SECTION rather than to the tab panel.
  - `@spec openspec/changes/the-case-page-finished/specs/case-dashboard-view/spec.md`
  - an assertion made against a merged panel can be satisfied by the wrong
    half of it, which is a test that passes for the wrong reason
  - `npx vitest run` exits 0

## 4. Not in this change

- [ ] 4.1 The four header actions, the raw-data sidebar tab, the dispatch
  columns and the delegate window from the DQ2 plan section.
- [ ] 4.2 Plan item 3.18, moving the task pane into the right column. It is
  deliberately not done: that column carries four cards already.
