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

- [x] 3.1 `src/dialogs/BulkTransitionDialog.vue` takes prop `mode`
  (`transition` default, `suspend`, `resume`, `extend`) and a required
  `reason` textarea; Execute stays disabled while the reason is empty, and
  in `extend` until a "New deadline" date is set; the title names the
  gesture. `suspend` also offers the days field the single-case dialog has,
  because `DeadlinePauseService::registerPauze` needs a positive duration and
  a silent default would be a term nobody chose.
  - The reason now gates TRANSITION too, where it was an optional comment.
    Reading back a batch of twenty cases that moved for no recorded reason is
    what that allowed. This changes the workflow board's dialog as well, which
    is the same component in its default mode.
  - The date input is a plain `<input type="date">`, the pattern every other
    dossiq dialog uses (`AddAssignmentDialog`, `ConsultationResponseForm`),
    not `NcDateTimePickerNative`.
  - `@spec openspec/changes/one-case-list/specs/case-bulk-status-transition/spec.md`
  - unit test in `tests/vitest/bulkTransitionDialog.spec.js`: a full mount per
    mode — previews on open without asking for a transition, Execute disabled
    on an empty reason in every mode, extend also waiting for a date, the
    payload per mode, the per-case summary on partial failure, and the
    transition mode still loading its available transitions
- [x] 3.2 `src/utils/bulkTransitionHelpers.js`: `buildLifecyclePreviewPayload`
  and `buildLifecycleExecutePayload` beside the transition builders, plus
  `isLifecycleGesture`; `summarizeResults` unchanged.
  - Two new builders rather than more parameters on `buildExecutePayload`, as
    the task proposed: a transition sends `{caseIds, transitionId, comment}`
    and a lifecycle gesture sends `{caseIds, gesture, reason, days?,
    newEndDate?}`, and one function returning either shape reads as a
    function with no shape at all. `days` goes only with suspend and
    `newEndDate` only with extend — a key the gesture cannot use is noise in
    the audit trail.
  - unit test in `tests/vitest/bulkTransitionHelpers.spec.js`, extended for
    the three shapes, the trimming, and the empty reason sent rather than
    dropped (the server has to be the one that refuses)
- [x] 3.3 `src/customComponents.js`: `transitionSelection`,
  `suspendSelection`, `resumeSelection` and `extendTermSelection` beside
  `reassignSelection`, all four mounting `BulkTransitionDialog` through one
  `openBulkDialog(mode, ids)` and signalling `dossiq:cases-changed` on
  `completed` — the same signal reassign sends.
  - `@spec openspec/changes/one-case-list/specs/case-bulk-status-transition/spec.md`
- [x] 3.4 `src/manifest.json` page `Cases` `bulkActions`: Transition
  (`SwapHorizontal`), Suspend (`PauseCircleOutline`), Resume
  (`PlayCircleOutline`) and Extend term (`CalendarPlus`) after Reassign. All
  four icons were already registered in `src/icons.js`.
  - unit test in `tests/vitest/caseListLenses.spec.js`: five bulk actions in
    order, every handler both DEFINED and EXPORTED in `src/customComponents.js`
    (a function missing from the default export is invisible to the renderer),
    every icon registered
- [x] 3.5 The bulk endpoints accept the three lifecycle gestures. The
  controller is `lib/Controller/StatusTransitionController.php`, not a
  `BulkTransitionController` — `statusTransition#bulkPreview` and
  `#bulkExecute` are what `appinfo/routes.php` binds — and both now read a
  `gesture` from the body: absent or unrecognised reads as `transition`, so
  the workflow board's dialog, which sends none, stays on exactly the path it
  was on. A lifecycle gesture with no reason is a 400 before anything is
  written. Per-case results keep the `{caseId: {status, reasons?}}` map.
  - The gestures loop `CaseLifecycleService::suspend/resume/extend` through
    two new `BulkStatusTransitionService` methods, rather than calling
    `DeadlinePauseService` directly as the task proposed. Those single-case
    gestures already own the case type's `suspensionAllowed` /
    `extensionAllowed` rules, the already-suspended and not-suspended checks,
    the required reason, the journal entry on the case and the term-instance
    write. Reaching past them to the pause service would have reimplemented
    every one of those guards, or shipped without them.
  - `CaseLifecycleService::extend` gained an optional `$newEndDate` so a
    reader can name the date; it must be LATER than the current end, and
    `extensionAllowed` still governs.
  - **[blocked: `case.deadline` is a read-only materialised calculation]**
    Extending a term does NOT move the Deadline column. `deadline` is
    `readOnly` on the case schema and computed by OpenRegister as
    `startDate + caseType.processingDeadline`
    (`x-openregister-calculations.deadline`, `materialise: true`), so it is
    recomputed on every save and would overwrite anything written to it. The
    extension writes `plannedEndDate` and the term instance's
    `endDateCurrent`, which is what the statutory clock, the daily scan and
    the dwangsom engine read. Making the column follow an extension means
    teaching the calculation to prefer `plannedEndDate`, which is a schema
    change with a version bump and an `occ upgrade` — and this change is
    `kind: config` and states "No schema change". The
    `case-bulk-status-transition` scenario was rewritten to assert what the
    gesture actually does.
  - `@spec openspec/changes/one-case-list/specs/case-bulk-status-transition/spec.md`
  - PHPUnit in `tests/Unit/Service/BulkStatusTransitionServiceTest.php` (the
    reason reaching each gesture, a refusal reported per case without
    aborting the batch, an empty reason refused before any write, an unknown
    gesture refused, the id cap), `tests/Unit/Controller/StatusTransitionControllerBulkTest.php`
    (no gesture and an unknown gesture both previewing a transition, a
    lifecycle gesture routed, the reason/days/date carried through, 400 on a
    missing reason) and `tests/Unit/Service/CaseLifecycleServiceTest.php`
    (an explicit date honoured, refused when not later or unreadable, and
    never bypassing the case type's rule)

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
