# Tasks: task-defaults-to-case-handler

Tier: V1. Kind: code. Row 3.8.

- [x] 1.1 `lib/Service/AssigneeResolver.php`: steps three and four (case
  assignee, assigned group), the `none` opt-out, the log line naming the
  step.
  - `tests/Unit/Service/AssigneeResolverTest.php`: handler, group, nobody,
    none; the authored and fallback cases unchanged
  - `@spec openspec/changes/task-defaults-to-case-handler/specs/task-management/spec.md`
- [x] 1.2 `lib/Service/Transitions/CreateTaskHandler.php` header: document
  the order and the reserved word.
- [x] 1.3 Fixture pair: `DossiqAskPersonNode` and `CreateTaskHandler` land
  on the same principal for the same case.
- [ ] 2.1 `tests/e2e/task-defaults-to-case-handler.spec.ts`; `openspec
  validate task-defaults-to-case-handler --strict`.
