# Tasks: task-search-fields

Tier: V1. Kind: config. Row 9.11. Waits on `remove-casetask`.

- [ ] 1.1 Measure: which of `subjectUuid`, `assignee`, due window, `state`,
  `priority` the inbox answers on openregister `development`; record the
  list here and note any missing one for openregister by parameter name.
- [ ] 1.2 `src/store/engineTasks.js`: map the sidebar fields to inbox
  arguments; unknown-argument errors log at ERROR naming the parameter.
  - vitest: every declared field has a mapping
  - `@spec openspec/changes/task-search-fields/specs/task-management/spec.md`
- [ ] 1.3 `src/manifest.json` `#Tasks` sidebar: the five fields with the
  right widgets (reference picker, user picker, date range, two facets).
  - `tests/vitest/caseListLenses.spec.js` sidebar block
- [ ] 2.1 `tests/e2e/task-search-fields.spec.ts`; `openspec validate
  task-search-fields --strict`.
