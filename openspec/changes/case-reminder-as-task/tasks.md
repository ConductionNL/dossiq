# Tasks: case-reminder-as-task

Tier: V1. Kind: config. Row 8.4. Waits on `remove-casetask` finishing
(3 tasks open) so the Tasks page reads the engine store.

- [ ] 1.1 `src/dialogs/RemindDialog.vue`: who, when, what; posts through
  `useEngineTaskStore` to `/api/flow-tasks` with `kind: reminder`.
  - vitest: one create with the expected payload; refused write surfaced
  - `@spec openspec/changes/case-reminder-as-task/specs/task-management/spec.md`
- [ ] 1.2 `src/manifest.json` `#CaseDetail` header action `case-remind`
  (`open-modal`, target `RemindDialog`).
  - `tests/vitest/caseActionsMenu.spec.js`
- [ ] 1.3 `#Tasks` sidebar facet on `kind` so reminders can be picked out.
- [ ] 2.1 `tests/e2e/case-reminder.spec.ts`; `openspec validate
  case-reminder-as-task --strict`.
