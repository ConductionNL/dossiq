# Tasks: task-on-the-case

Tier: V1. Kind: code. Every checkbox below is one implementation task; the
criteria under a task are plain bullets.

## 1. The task pane

- [x] 1.1 `src/components/tasks/CaseTaskPane.vue`: props `objectId`,
  `content`; fetches open `caseTask` rows (`case = objectId`,
  `isTerminalStatus = false`, sort `dueDate asc`, `limit` from content)
  through `useObjectStore`; renders the first row with title, assignee, due
  date and `CnLifecycleActions` in server mode (`config: { field: 'status' }`);
  on `transitioned` to `completed`, `terminated` or `disabled` calls
  `showSuccess` with the task title and refetches; on any other transition
  refetches and keeps the task; on failure calls `showError` with the
  server message and keeps the task; lists the remaining open tasks as links
  to `TaskDetail`; keeps View all through `viewAllRoute` and `viewAllQuery`;
  shows "No open tasks on this case" when empty. No em-dashes, sentence case.
  - unit test in `tests/vitest/caseTaskPane.spec.js` (NOT
    `tests/unit/components/`: `vitest.config.js` collects `tests/vitest/**`
    and explicitly EXCLUDES `tests/unit/**`, so a spec at the path this task
    named would never have run): first row and its buttons, final transition
    toasts and advances to the second task, activate keeps the task, a
    rejected transition keeps the task and shows the error, empty text when
    no rows. The pure decisions (open-task query, final-status set, route
    tokens) sit in `src/utils/caseTaskPaneHelpers.js` with their own
    node-environment spec, the split `flowTaskHelpers.js` already uses.
  - the refusal path is a WATCHER, not an event handler: `CnLifecycleActions`
    declares `emits: ['transitioned', 'reload']` and emits NOTHING when the
    server refuses a transition, putting the message in its own `error` data
    and rendering it inline. Verified in the installed 2.40.0 dist and in the
    published 2.41.1 tarball. The pane watches the child's `error` through a
    ref and forwards it to `showError`.
  - `@spec openspec/changes/task-on-the-case/specs/task-management/spec.md`
- [x] 1.2 `src/registry.js`: register `CaseTaskPane` (kind `widget`) with a
  `@custom-widget-ratchet exclude` note reading `blocked: nextcloud-vue
  2.41.1 CnObjectListWidget has no rowActions and no lifecycle column`, and
  the `@spec` line above it.
  - registered under the WIDGET TYPE key `case-task-pane`, NOT under the page
    slot `widget-case-tasks` this task named. `case-tasks` is a child of the
    `case-panels` tabs widget and is deliberately absent from `layout` (a
    layout entry would render it twice). CnDetailPage renders a
    `widget-<id>` slot per GRID item only, so the slot would never be
    rendered; CnTabsWidget renders its children through CnDetailWidgetHost,
    which resolves `cnRegistry[widget.type]` and otherwise renders NOTHING
    and logs nothing. Both halves read out of the installed dist.
  - unit test in `tests/vitest/manifestCaseTaskPane.spec.js`: the widget type
    resolves to the component, the entry carries a `_note` and a
    reason-bearing exclusion, and the import points at a file that exists.
    `src/registry.js` is read as text, not imported: it pulls in ~40 SFCs and
    a probe import did not settle inside the 5s test budget.
- [x] 1.3 `src/manifest.json`: widget `case-tasks` on `CaseDetail` changes
  `type` from `object-list` to `case-task-pane` (the registry key, see 1.2,
  not the literal `custom`); `id`, `title`, `icon`, its entry in the
  `case-panels` tab strip and every `content` key (register, schema, filter,
  sort, limit, columns, rowRoute, viewAllRoute, viewAllQuery, emptyText)
  stay; the `_note` names the block and the target (back to `object-list`
  with a lifecycle column). It has no grid cell to keep: it is a tab child
  and deliberately absent from `layout`.
  - unit test in `tests/vitest/manifestCaseTaskPane.spec.js`: widget id,
    title and icon unchanged, type resolves in the registry, still a tab of
    `case-panels` and still out of `layout`, every content key preserved,
    page count 43 as before, custom pages 10 as before, and the icon
    registered in `src/icons.js` (gate 60)
  - hydra gates run locally at the end of the change; the ADR-100 page
    ratchet reads off the page counts above, which are unchanged
- [x] 1.4 `l10n/nl.json`: "No open tasks on this case" as "Geen open taken
  op deze zaak"; the toast text "Task {title} finished" as "Taak {title}
  afgerond". Landed with 1.1 rather than after it: `check-l10n.js` fails the
  moment a `t()` call has no `en.json` key, so splitting them would leave the
  branch red between two commits. Two further strings the pane needs came
  with them ("Other open tasks", "No due date"), and all four went into
  `en.js`/`nl.js` beside the JSON, which is what the app loads at runtime.

## 2. The way back

- [x] 2.1 `src/components/tasks/TaskCaseLink.vue`: prop `objectId` (the
  task); reads `caseTask.case`, fetches the case title, renders a
  `router-link` to `CaseDetail` for that case; renders nothing when `case`
  is empty. It also accepts `objectData`, which the page slot binds
  alongside `objectId`, and skips the task read when the surface already
  holds the task. `caseIdFrom` in `src/utils/flowTaskHelpers.js` was made
  public rather than copied: a second reader of the same `$ref` shapes is
  the copy that drifts.
  - unit test in `tests/vitest/taskCaseLink.spec.js`: link text is the case
    title and the route carries the CASE id (not the task id, which renders
    an identically plausible link), an expanded `$ref` is read too, an
    unreadable title still links, an empty `case` renders no element at all,
    and an unreadable task renders none either
  - `@spec openspec/changes/task-on-the-case/specs/task-management/spec.md`
- [x] 2.2 `src/registry.js`: register `TaskCaseLink` for the page slot
  `widget-task-case-link` with a ratchet note (a cross-object link by
  title; no built-in resolves a `$ref` to a label, placement A35). Keyed by
  COMPONENT NAME here, unlike `case-task-pane`: `task-case-link` sits in
  TaskDetail's `layout`, so CnDetailPage does render its `widget-<id>` slot
  and `page.slots` maps it to this key.
  - unit test in `tests/vitest/manifestCaseTaskPane.spec.js`: the key
    carries a `_note` and a reason-bearing exclusion, and its import points
    at a file that exists
- [x] 2.3 `src/manifest.json`: `TaskDetail` gains widget `task-case-link`,
  type `custom`, `showTitle: false`, placed above `task-data`;
  `task-waiting-case` stays as it is. The widget also gets a LAYOUT entry and
  a `slots` entry: a `widget-<id>` slot is rendered per grid item, so without
  the layout row the slot is never rendered and the component never mounts.
  - unit test in `tests/vitest/manifestCaseTaskPane.spec.js`: the widget
    exists, is type `custom`, resolves through `slots.widget-task-case-link`,
    has a layout entry above `task-data` with `showTitle: false`, leaves
    `task-waiting-case` alone, names an icon `src/icons.js` registers, and
    the page count is unchanged

## 3. End to end

- [x] 3.1 `tests/e2e/case-task-pane.spec.ts`: seeds one case and two open
  tasks (first active, second available with a later due date) through the
  OpenRegister objects API with `page.request` and the harvested token;
  asserts by widget id `case-tasks` and by button label; covers: the open
  task shows its buttons on the case; completing confirms with a toast, the
  route stays on the case, the second task appears with its own buttons;
  the last task leaves the empty text and View all shows it completed; the
  task page names its case and following the link opens the case page.
  Labels asserted are the schema descriptions, which are English on CI.
  - the spec must appear in `tests/e2e/playwright.config.ts`'s project, the
    config CI reads. Confirmed by `npx playwright test --config
    tests/e2e/playwright.config.ts --list`: all four tests are collected
    under `[chromium]`, out of 252 in 54 files.
  - FOUR cases are seeded, not one. Two of these tests complete a task, so a
    shared case would make the tests order-dependent, and an order dependency
    is the failure that only reproduces on the second run.
  - the two active tasks are driven through OpenRegister's `transition` route
    rather than seeded with `status: "active"`, and the resulting status is
    read back. That both seeds the state and proves the lifecycle is live
    before any browser opens.
  - `caseTask`, `case` and `caseType` are already in `tests/e2e/ci-seed.sh`'s
    required-schema list, so the seed needs no change.
- [ ] 3.2 Run `npm run lint`, `npm run test:unit` and the e2e spec locally;
  read the exit codes, not the summary lines; say in the PR which checks
  ran locally only.

## 4. Follow-up

- [x] 4.1 Open an issue on ConductionNL/nextcloud-vue: `CnObjectListWidget`
  needs a `rowActions` or lifecycle column so `case-tasks` can return to
  `object-list`; link it from the `_note` in 1.3 and from the ratchet note
  in 1.2. Filed as ConductionNL/nextcloud-vue#1033 and linked from both. It
  carries two further asks the implementation turned up: `CnLifecycleActions`
  emits nothing on a refused transition, so a consumer has to watch the
  child's internal `error` through a ref; and `CnDetailPage` renders a
  `widget-<id>` slot per grid item only, so a `type: "custom"` tab child
  resolves to nothing and renders nothing with no console output.
