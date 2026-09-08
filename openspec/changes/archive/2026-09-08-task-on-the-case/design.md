# Design: task-on-the-case

## Context

Widget `case-tasks` on `CaseDetail` (`src/manifest.json`) is an
`object-list` over `caseTask` filtered on `case = @objectId`, sorted on
`dueDate`, with `rowRoute: TaskDetail` and `viewAllRoute: Tasks`. In
nextcloud-vue 2.40.0 `CnObjectListWidget` (`dist/esm/components/
CnObjectListWidget/CnObjectListWidget.vue2.js`) accepts `register`,
`schema`, `filter`, `sort`, `limit`, `columns`, `rowRoute`, `prompt`,
`emptyText`, `viewAllRoute` and `viewAllQuery`. It has no `rowActions`, no
lifecycle column and no per-row slot; a row click navigates or emits
`row-click`. It cannot render a button inside a row.

`CnLifecycleActions` (`dist/esm/components/CnLifecycleActions/`) takes
`objectId` and a `config` with an optional `transitions` list. Without a
list it calls `GET /apps/openregister/api/objects/{id}/available-actions`
and renders what comes back; on a click it posts the transition, emits
`transitioned` with `{action, to, object}` and then `reload`. Triage item 1
shows the route answers `complete / terminate / disable` for an active task
because `caseTask.status` carries a valid lifecycle block
(`lib/Settings/dossiq_register.json`, `x-openregister-lifecycle` on
`caseTask`: activate from available, complete from active, terminate and
disable from either; final completed, terminated, disabled; the calculated
`isTerminalStatus` mirrors `final`).

`TaskDetail` already carries one `custom` widget, `task-waiting-case`,
resolved through the page slot `widget-task-waiting-case` to
`TaskWaitingCaseSection` in `src/registry.js` (kind `widget`, with a
`@custom-widget-ratchet exclude` note). That is the house pattern for a
widget the catalogue cannot express.

## Decision

**Interim: a dossiq `CaseTaskPane` custom widget.** `[blocked: nextcloud-vue
2.40.0 CnObjectListWidget has no rowActions and no lifecycle column]`. The
widget keeps its id `case-tasks`, its title, icon and grid cell, and changes
type from `object-list` to `custom`. `src/registry.js` maps
`widget-case-tasks` to `CaseTaskPane` with a ratchet note quoting the block.
No page is added or retyped, so the ADR-100 page ratchet is untouched.

**Target: the widget goes back to `object-list`.** When nextcloud-vue ships a
`rowActions` or lifecycle column on `CnObjectListWidget`, the manifest
declares it and `CaseTaskPane` is deleted. The e2e spec asserts on the
widget id and the button labels, not on the component, so it survives the
swap.

The other option, keeping `object-list` and adding `rowActions`, is not
available: the prop does not exist in the dist, and a config key the
component does not declare is dropped silently.

## CaseTaskPane

Props: `objectId` (the case), `content` (the widget content, unchanged).

1. Fetch open tasks through `useObjectStore` over `caseTask` with
   `case = objectId`, `isTerminalStatus = false`, sort `dueDate asc`,
   limit from `content.limit`. The server-side `isTerminalStatus` filter is
   used because a client-side filter over paged rows drops what it never
   fetched.
2. Render the first row as the pane: title, assignee, due date, and
   `<CnLifecycleActions :object-id="task.id" :config="{ field: 'status' }">`.
   Server mode, no declared transitions: the labels come from the schema's
   transition descriptions, the same ones `TaskDetail` shows.
3. On `transitioned`: when `to` is in the lifecycle's final set
   (`completed`, `terminated`, `disabled`) call `showSuccess` from
   `@nextcloud/dialogs` with the task title, refetch, and render the new
   first row; otherwise (activate) refetch and keep the same task with its
   new buttons. Failures surface through `showError` with the server
   message; the pane keeps the task.
4. Rows after the first render as a compact list under the pane, each a
   link to `TaskDetail`. The footer keeps View all through `viewAllRoute`
   and `viewAllQuery`.
5. No open task: the text "No open tasks on this case".

Copy follows the writing skill: no em-dashes, sentence case.

## TaskCaseLink

A second small component behind the page slot `widget-task-case-link` on
`TaskDetail`: reads `caseTask.case`, fetches the case title, renders a
`router-link` to `CaseDetail`. Renders nothing when `case` is empty. It is
a separate component from `TaskWaitingCaseSection` because the two answer
different questions ("which case is this on" versus "which run is waiting
on me") and the waiting one stays deliberately null for ordinary tasks.

## Seam with case-flow-human-steps

`TaskCompletionResumeListener` (`lib/Listener/`) reacts to the object
update that the `complete` transition performs, so the pane needs no
knowledge of flows: the write goes through the lifecycle route, the
listener resumes the run, the flow creates the next task, and the pane's
refetch shows it. When the flow has not yet created the next task at the
moment of the refetch, the pane shows the next existing open task or the
empty text; the live-updates change (`adopt-live-updates-ui`) will make the
new task appear without a reload. This change does not wait on it.

## Testing

- vitest: `tests/unit/components/CaseTaskPane.spec.js` (first row and
  buttons; final transition shows a toast and advances; non-final keeps
  the task; error keeps the task; empty text) and
  `tests/unit/components/TaskCaseLink.spec.js` (link and null render).
- vitest: `tests/unit/manifest-case-task-pane.spec.js` (widget id, type
  `custom`, page count unchanged, slot registered).
- Playwright: `tests/e2e/case-task-pane.spec.ts`, seeding two tasks on one
  case through the OpenRegister objects API as `page.request` with the
  harvested token (a CSRF-guarded GET answers 412 as valid JSON).
