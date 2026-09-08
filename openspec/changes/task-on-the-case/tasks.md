# Tasks: task-on-the-case

Tier: V1. Kind: code. Every checkbox below is one implementation task; the
criteria under a task are plain bullets.

## 1. The task pane

- [ ] 1.1 `src/components/tasks/CaseTaskPane.vue`: props `objectId`,
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
  - unit test in `tests/unit/components/CaseTaskPane.spec.js`: first row
    and its buttons, final transition toasts and advances to the second
    task, activate keeps the task, a rejected transition keeps the task and
    shows the error, empty text when no rows
  - `@spec openspec/changes/task-on-the-case/specs/task-management/spec.md`
- [ ] 1.2 `src/registry.js`: register `CaseTaskPane` (kind `widget`) for the
  page slot `widget-case-tasks` with a `@custom-widget-ratchet exclude` note
  reading `blocked: nextcloud-vue 2.40.0 CnObjectListWidget has no
  rowActions and no lifecycle column`, and the `@spec` line above it.
  - unit test in `tests/unit/manifest-case-task-pane.spec.js`: the slot
    resolves to the component
- [ ] 1.3 `src/manifest.json`: widget `case-tasks` on `CaseDetail` changes
  `type` from `object-list` to `custom`; `id`, `title`, `icon`, grid cell
  and `content` (register, schema, filter, sort, limit, columns, rowRoute,
  viewAllRoute, viewAllQuery, emptyText) stay; the `_note` names the block
  and the target (back to `object-list` with a lifecycle column).
  - unit test in `tests/unit/manifest-case-task-pane.spec.js`: widget id
    unchanged, type `custom`, still on `CaseDetail`, page count equal to the
    count before this change, no page of type `custom` added
  - run the hydra gates locally and read the ADR-100 ratchet count; it
    must not move
- [ ] 1.4 `l10n/nl.json`: "No open tasks on this case" as "Geen open taken
  op deze zaak"; the toast text "Task {title} finished" as "Taak {title}
  afgerond".

## 2. The way back

- [ ] 2.1 `src/components/tasks/TaskCaseLink.vue`: prop `objectId` (the
  task); reads `caseTask.case`, fetches the case title, renders a
  `router-link` to `CaseDetail` for that case; renders nothing when `case`
  is empty.
  - unit test in `tests/unit/components/TaskCaseLink.spec.js`: link text
    is the case title and the route carries the case id; empty `case`
    renders no element
  - `@spec openspec/changes/task-on-the-case/specs/task-management/spec.md`
- [ ] 2.2 `src/registry.js`: register `TaskCaseLink` for the page slot
  `widget-task-case-link` with a ratchet note (a cross-object link by
  title; no built-in resolves a `$ref` to a label, placement A35).
- [ ] 2.3 `src/manifest.json`: `TaskDetail` gains widget `task-case-link`,
  type `custom`, `showTitle: false`, placed above `task-data`;
  `task-waiting-case` stays as it is.
  - unit test in `tests/unit/manifest-case-task-pane.spec.js`: the widget
    exists, precedes `task-data`, page count unchanged

## 3. End to end

- [ ] 3.1 `tests/e2e/case-task-pane.spec.ts`: seeds one case and two open
  tasks (first active, second available with a later due date) through the
  OpenRegister objects API with `page.request` and the harvested token;
  asserts by widget id `case-tasks` and by button label; covers: the open
  task shows its buttons on the case; completing confirms with a toast, the
  route stays on the case, the second task appears with its own buttons;
  the last task leaves the empty text and View all shows it completed; the
  task page names its case and following the link opens the case page.
  Labels asserted are the schema descriptions, which are English on CI.
  - the spec must appear in `tests/e2e/playwright.config.ts`'s project, the
    config CI reads
- [ ] 3.2 Run `npm run lint`, `npm run test:unit` and the e2e spec locally;
  read the exit codes, not the summary lines; say in the PR which checks
  ran locally only.

## 4. Follow-up

- [ ] 4.1 Open an issue on ConductionNL/nextcloud-vue: `CnObjectListWidget`
  needs a `rowActions` or lifecycle column so `case-tasks` can return to
  `object-list`; link it from the `_note` in 1.3 and from the ratchet note
  in 1.2.
