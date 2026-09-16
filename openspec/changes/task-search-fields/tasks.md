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
- [ ] 1.2 `src/store/engineTasks.js`: map the sidebar fields to inbox
  arguments; unknown-argument errors log at ERROR naming the parameter.
  - vitest: every declared field has a mapping
  - `@spec openspec/changes/task-search-fields/specs/task-management/spec.md`
- [ ] 1.3 `src/manifest.json` `#Tasks` sidebar: the five fields with the
  right widgets (reference picker, user picker, date range, two facets).
  - `tests/vitest/caseListLenses.spec.js` sidebar block
- [ ] 2.1 `tests/e2e/task-search-fields.spec.ts`; `openspec validate
  task-search-fields --strict`.
