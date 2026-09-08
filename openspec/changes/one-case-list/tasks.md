# Tasks: one-case-list

Tier: V1. Kind: config. Every checkbox below is one implementation task; the
criteria under a task are plain bullets. D1 revised: Queue and MyWork stay,
no menu entry changes.

## 1. Lenses

- [x] 1.1 `src/manifest.json` page `Cases`: `config.quickFilters` holds All
  (`{}`, `default: true`), Mine (`{ assignee: "@me", isFinalStatus: false }`),
  Unclaimed (`{ assignee: "IS NULL", isFinalStatus: false }`), Closed
  (`{ isFinalStatus: true }`), Overdue (`{ "deadline[lt]": "@today",
  isFinalStatus: false }`), in that order; `_quickFiltersNote` names D1
  revised, D-default revised and the Queue page's identical Unclaimed filter.
  - `@spec openspec/changes/one-case-list/specs/my-work/spec.md`
  - Two departures from the task as written, both recorded in the manifest
    note. **All is the default, not Mine** (D-default revised; a Mine default
    narrows the first paint before the reader chooses). **The Overdue operator
    is the flat key `deadline[lt]`, not `{ deadline: "lt @today" }`**:
    `buildQueryString` JSON-stringifies a nested object value, so the nested
    form reaches the API as the literal `{"lt":"@today"}` and matches nothing,
    silently. The flat form is what the Overdue dashboard tile already sends.
  - unit test in `tests/vitest/caseListLenses.spec.js` (the suite lives under
    `tests/vitest/`, not `tests/unit/`): five chips in order, All default and
    nothing else default, Unclaimed filter deep-equals `Queue.config.filter`,
    Mine and Unclaimed carry `isFinalStatus: false`, Closed carries `true`,
    `menu` unchanged, Queue and MyWork still present
- [x] 1.2 `src/manifest.json` page `Tasks`: `config.quickFilters` holds All
  (`{}`, default), Mine (`{ assignee: "@me", isTerminalStatus: false }`),
  Unclaimed (`{ assignee: "IS NULL", isTerminalStatus: false }`).
  - `@spec openspec/changes/one-case-list/specs/task-management/spec.md`
  - unit test in `tests/vitest/caseListLenses.spec.js`: three chips, All
    default, same label set as the first three on Cases

## 2. Columns and sidebar

- [x] 2.1 `src/manifest.json` page `Cases` `columns`: the `deadline` entry is
  `{ key: "deadline", label: "Deadline", widget: "deadlineCountdown" }`, in
  its place after `assignee`. The interim the task anticipated is the one
  that shipped, in a better shape than a formatter: 2.41 ships NO countdown
  column cell (only `CnCountdownWidget`, a dashboard tile), and a formatter
  could not carry the overdue state at all, because a formatter returns a
  string. `cnCellWidgets` is the seam for exactly this, so the cell is a
  component: `src/components/cells/DeadlineCountdownCell.vue`, registered as
  `deadlineCountdown` in the new `src/services/cellWidgets.js` and passed to
  `CnAppRoot` through App.vue's new `cellWidgets` prop. The arithmetic is the
  pure `src/utils/deadlineCountdown.js`. Overdue reads in
  `var(--color-error-text)`, not `--color-error`, which is a FILL token on
  NC34 and renders pale pink on white.
  - `@spec openspec/changes/one-case-list/specs/signalering-widgets/spec.md`
  - unit tests in `tests/vitest/deadlineCountdown.spec.js` (3 days left,
    2 days overdue, empty on null/unparseable, today reads 0 days left at any
    hour, "1 day" not "1 days", a full ISO instant read as its calendar day,
    a date-only string read as a LOCAL day) and
    `tests/vitest/deadlineCountdownCell.spec.js` (the `is-overdue` class, the
    empty cell, the title, the test id)
- [ ] 2.2 `src/manifest.json` page `Cases` `sidebar`: add filter
  `{ field: "deadline", operator: "lt", label: "Deadline before",
  type: "date" }`. **[blocked: nextcloud-vue 2.41's index sidebar has no
  manifest-declared filter and no operator]** `CnIndexSidebar` builds its
  Filters section from `filtersFromSchema`, which walks the schema's
  `facetable: true` properties and renders a checkbox or a values select;
  its `filter-change` event carries `{ key, values }`. There is no manifest
  key for a sidebar filter, no date input, and no operator anywhere on that
  path, so no configuration in this repo can express `deadline lt <date>`
  as a control. Writing the key into `sidebar` would validate (the schema
  allows extra properties) and render nothing — the silent kind of no-op.
  - Interim: the state is reachable, just not offerable. A route query goes
    straight into the fetch, so `/cases?deadline[lt]=2026-10-01` narrows the
    list exactly as the requirement describes, and that is the path both
    Overdue dashboard tiles take (2.3). The Overdue chip covers the one
    deadline window a reader asks for most.
  - Unblocking is a nextcloud-vue change: `sidebar.filters[]` of
    `{ field, operator, label, type }` on `CnIndexPage`, rendered beside the
    schema facets and merged into the fetch the way the facets already are.
    Recorded in the `case-management` delta, whose scenario now carries an
    `@e2e exclude` reason.
  - `@spec openspec/changes/one-case-list/specs/case-management/spec.md`
- [x] 2.3 BOTH dashboard Overdue widgets now carry the Overdue chip's filter:
  the `overdue-cases` object-table's `viewAllRoute.query` and the
  `kpi-overdue` stat tile's `route.query`, which counted OPEN overdue cases
  and linked to EVERY overdue case, closed ones included. Values stay
  strings, because a route query is a URL and `dashboardViewAllRoutes.spec.js`
  holds that invariant for the whole dashboard; the equality is asserted over
  the stringified chip filter.
  - The task's last clause is not true of the library and is not implemented:
    `CnIndexPage` does NOT activate the chip whose filter equals the query.
    `resolveInitialQuickFilterIndex` reads only the `default` flag, so a
    reader arriving from a tile lands on All with the tile's filter applied
    through the query — the list is right, the filter is in the URL, and no
    chip lights up to say which lens is on. Naming a chip from a query is a
    nextcloud-vue change; the `signalering-widgets` scenario was rewritten to
    assert what the filter surviving the trip actually looks like, which is
    the defect triage item 4 named.
  - unit test in `tests/vitest/caseListLenses.spec.js`: both tiles route to
    `Cases` and both queries deep-equal the stringified Overdue chip filter

## 3. Bulk actions

- [ ] 3.1 `src/dialogs/BulkTransitionDialog.vue`: add prop `mode`
  (`transition` default, `suspend`, `resume`, `extend`) and a required
  `reason` textarea (`NcTextArea`, label "Reason"); Execute disabled while
  the reason is empty; `extend` mode shows a date input "New deadline"
  (`NcDateTimePickerNative`) required before Execute; the title and the
  summary phrasing follow the mode. Reason posts as `comment` on
  `buildExecutePayload`; `suspend` and `resume` post `{ pause: { reason } }`
  and `{ resume: true }`; `extend` posts `{ deadline, reason }`.
  - `@spec openspec/changes/one-case-list/specs/case-bulk-status-transition/spec.md`
  - unit test in `tests/unit/dialogs/BulkTransitionDialog.spec.js`: Execute
    disabled on empty reason in every mode, enabled with reason and (for
    extend) a date, payload shape per mode, per-case summary on partial
    failure
- [ ] 3.2 `src/utils/bulkTransitionHelpers.js`: `buildExecutePayload`
  accepts the mode payloads above; `summariseResults` unchanged.
  - unit test in `tests/unit/utils/bulkTransitionHelpers.spec.js` extended
    for the three new shapes
- [ ] 3.3 `src/customComponents.js`: handlers `transitionSelection`,
  `suspendSelection`, `resumeSelection`, `extendTermSelection` beside
  `reassignSelection`, each mounting `BulkTransitionDialog` with
  `{ caseIds: selectedIds, mode }` and refreshing the list on `completed`.
  - `@spec openspec/changes/one-case-list/specs/case-bulk-status-transition/spec.md`
- [ ] 3.4 `src/manifest.json` page `Cases` `bulkActions`: add Transition
  (`SwapHorizontal`, `transitionSelection`), Suspend (`PauseCircleOutline`,
  `suspendSelection`), Resume (`PlayCircleOutline`, `resumeSelection`),
  Extend term (`CalendarPlus`, `extendTermSelection`) after Reassign.
  - unit test in `tests/unit/manifest-case-list-lenses.spec.js`: five bulk
    actions, handlers exported from `src/customComponents.js`
- [ ] 3.5 `lib/Controller/BulkTransitionController.php` (the endpoints the
  board uses): accept the `pause`, `resume` and `deadline` payloads;
  `pause` calls `DeadlinePauseService::registerPauze` with the reason,
  `resume` calls `resumeAfterPauze`, `deadline` writes the case deadline
  and a status record note with the reason; per-case results keep the
  `{caseId: {status, reasons?}}` map.
  - `@spec openspec/changes/one-case-list/specs/case-bulk-status-transition/spec.md`
  - PHPUnit in `tests/Unit/Controller/BulkTransitionControllerTest.php`:
    pause and resume reach the service with the reason, a refused case is
    reported per case, a missing reason is a 400

## 4. Copy and locale

- [ ] 4.1 `l10n/nl.json`: Mine "Van mij", Unclaimed "Niet toegewezen", All
  "Alle", Closed "Gesloten", Overdue "Verlopen", "Deadline before"
  "Deadline voor", "{n} days left" "{n} dagen over", "{n} days overdue"
  "{n} dagen verlopen", "Extend term" "Termijn verlengen", "Suspend"
  "Opschorten", "Resume" "Hervatten", "Reason" "Reden", "New deadline"
  "Nieuwe deadline". Sentence case, no em-dashes (writing skill, gate 96).

## 5. Verification

- [ ] 5.1 `tests/e2e/case-list-lenses.spec.ts`: seeds cases (mine open,
  other user's open, unassigned open, closed, overdue open, closed overdue,
  due in 3 and 30 days) and tasks (mine open, other's open, unassigned
  open, completed); covers every `@e2e` scenario in the five deltas: chips
  on landing and after a click, Unclaimed equals Queue, Deadline before,
  days left and overdue class, Overdue tile View all, Transition with
  reason, Execute disabled on empty reason, Suspend then Resume, Extend
  term. Assert ids and `data-testid`, not English labels alone.
- [ ] 5.2 Run `npm run check:manifest`, `npm run test:unit`,
  `composer check:strict`, the hydra gates locally (read the gate count and
  the ADR-100 ratchet: no page added), and `openspec validate one-case-list
  --strict`; then the e2e spec against the dev instance before opening the
  PR with `--base development`.
