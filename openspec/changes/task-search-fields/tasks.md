# Tasks: task-search-fields

Tier: V1. Kind: config. Row 9.11. Waits on `remove-casetask`.

- [x] 1.1 Measure: which of `subjectUuid`, `assignee`, due window, `state`,
  `priority` the inbox answers on openregister `development`; record the
  list here and note any missing one for openregister by parameter name.

  Measured 2026-09-16 against `ConductionNL/openregister@development`,
  reading `TaskController::index()` (the parameter list the route binds) and
  `TaskInboxCriteria` (the predicates both `findInbox` and `countInbox` run).
  Four of the five are answered server-side, one is not:

  | Sidebar field | Inbox argument | Answered |
  |---|---|---|
  | case | `objectUuid` | yes |
  | due between | `dueAfter` + `dueBefore`, ISO-8601 instants | yes |
  | state | `state`, comma separated, plus `isTerminal` | yes |
  | priority | `priority`, one value | yes |
  | assignee | none | no |

  🔴 `assignee` IS THE GAP, AND IT IS REFUSED TWICE OVER. `TaskInboxCriteria`
  has no assignee parameter at all: the only assignee-shaped narrowing is
  `scope`, which `assigneeNames()` binds to the CALLING uid. The library's
  `useTaskInboxStore` then drops the key a second time, and says why: its
  allowlist exists so that "no config key may widen whose inbox this is".
  So the field is not declared, per design D-2. The ask for openregister is
  one parameter on `TaskController::index()` and one predicate on
  `TaskInboxCriteria`, both named `assignee`, taking a performer reference in
  the `type:id` spelling `TaskController::assign()` already accepts, and
  authorised as a read the caller may already make. Written up in the PR
  body rather than faked here.
- [x] 1.2 `src/store/engineTasks.js`: map the sidebar fields to inbox
  arguments; unknown-argument errors log at ERROR naming the parameter.
  - vitest: every declared field has a mapping
  - `@spec openspec/changes/task-search-fields/specs/task-management/spec.md`

  🔑 THE MAPPING LANDED IN NEXTCLOUD-VUE, NOT IN dossiq's ENGINE TASK STORE,
  and the proposal's "the engine task store maps each" was written before
  anyone read which store the page uses. `src/store/modules/engineTask.js`
  drives the case pane and the widgets; the Tasks INDEX is a
  `CnIndexPage` over `entitySource: tasks`, whose reads go through the
  library's own `useTaskInboxStore`. Teaching dossiq's store instead would
  have mapped filters onto a store the page never calls: four controls on
  screen, one store correctly filtered, and nothing on the list changing.
  Every app running its work on OpenRegister's one task store needs the
  same mapping, so it sits on the source (ADR architecture rule: logic that
  belongs to another app is built there and consumed here).

  Landed as ConductionNL/nextcloud-vue#1188: a source declares
  `searchFields`, `useNamedSource` merges the sidebar's values into every
  load above the lens filter, the page holds the state and mirrors it into
  `$route.query`, and a chosen field with no mapping logs at ERROR naming
  itself. dossiq keeps the half it owns, the declaration, and
  `tests/vitest/taskSearchFields.spec.js` asserts the two halves agree.
- [x] 1.3 `src/manifest.json` `#Tasks` sidebar: the five fields with the
  right widgets (reference picker, user picker, date range, two facets).
  - `tests/vitest/caseListLenses.spec.js` sidebar block

  FOUR fields, not five: the user picker is the `assignee` gap from 1.1.
  Declared under `config.sidebar.fields` rather than `config.schema`, for
  two reasons. The manifest schema types `config.schema` as a STRING,
  because it names the OpenRegister schema a page self-fetches from and
  this page fetches from the engine (`check:manifest` refuses the object
  outright). And a schema feeds BOTH sidebar tabs, so a filter declared
  there also appears in the Columns tab, offering a column the task table
  does not have. `sidebar.fields` reaches the Search tab and nothing else.
- [x] 2.1 `tests/e2e/task-search-fields.spec.ts`; `openspec validate
  task-search-fields --strict`.

  The e2e probes each predicate on the wire in `beforeAll` before asking
  the browser anything, so an openregister that drops an argument it does
  not declare names itself instead of reading as "the sidebar does not
  narrow". Every scenario asserts BOTH directions, the row that stays and
  the row that goes: a one-sided assertion passes on a filter that does
  nothing.
