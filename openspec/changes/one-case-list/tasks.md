# Tasks: one-case-list

Tier: V1. Kind: config. Every checkbox below is one implementation task; the
criteria under a task are plain bullets. D1 revised: Queue and MyWork stay,
no menu entry changes.

## 1. Lenses

- [ ] 1.1 `src/manifest.json` page `Cases`: add `config.quickFilters` with
  Mine (`{ assignee: "@me", isFinalStatus: false }`, `default: true`),
  Unclaimed (`{ assignee: "IS NULL", isFinalStatus: false }`), All (`{}`),
  Closed (`{ isFinalStatus: true }`), Overdue (`{ deadline: "lt @today",
  isFinalStatus: false }`), in that order; a `_note` naming D1 revised and
  the Queue page's identical Unclaimed filter.
  - `@spec openspec/changes/one-case-list/specs/my-work/spec.md`
  - unit test in `tests/unit/manifest-case-list-lenses.spec.js`: five chips
    in order, Mine default, Unclaimed filter deep-equals `Queue.config.filter`,
    Mine and Unclaimed carry `isFinalStatus: false`, Closed carries `true`,
    `menu` and the page count unchanged
- [ ] 1.2 `src/manifest.json` page `Tasks`: add `config.quickFilters` Mine
  (`{ assignee: "@me", isTerminalStatus: false }`, default), Unclaimed
  (`{ assignee: "IS NULL", isTerminalStatus: false }`), All (`{}`).
  - `@spec openspec/changes/one-case-list/specs/task-management/spec.md`
  - unit test in `tests/unit/manifest-case-list-lenses.spec.js`: three
    chips, Mine default, same label set as the first three on Cases

## 2. Columns and sidebar

- [ ] 2.1 `src/manifest.json` page `Cases` `columns`: the `deadline` entry
  becomes `{ key: "deadline", label: "Deadline", widget: "countdown" }`;
  keep the column position after `assignee`. If the 2.40.0 data table has
  no countdown cell, add formatter `deadlineCountdown` in
  `src/customComponents.js` (days left, "{n} days overdue" past due, class
  `is-overdue`, empty when `deadline` is empty) and note the block in the
  column `_note`.
  - `@spec openspec/changes/one-case-list/specs/signalering-widgets/spec.md`
  - unit test in `tests/unit/customComponents/deadlineCountdown.spec.js`:
    3 days left, 2 days overdue with class, empty on null, today reads
    0 days left
- [ ] 2.2 `src/manifest.json` page `Cases` `sidebar`: add filter
  `{ field: "deadline", operator: "lt", label: "Deadline before",
  type: "date" }`; keep `showMetadata`. Place the Requester text filter
  from `requester-on-the-case` (REQ-ID-2) beside it only if that change
  has landed; do not add the Requester column here.
  - `@spec openspec/changes/one-case-list/specs/case-management/spec.md`
  - unit test in `tests/unit/manifest-case-list-lenses.spec.js`: the sidebar
    filter exists with operator `lt` and type `date`
- [ ] 2.3 Dashboard Overdue tile (`src/manifest.json`, the signalering
  widget with the overdue filter): its `viewAllRoute` stays `Cases` and its
  `viewAllQuery` becomes the Overdue chip filter; `CnIndexPage` activates
  the chip whose filter equals the query.
  - unit test in `tests/unit/manifest-case-list-lenses.spec.js`: the tile
    query deep-equals the Overdue chip filter

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
