# Tasks: the-ask-step-asks-for-fields

Tier: MVP. Kind: code. Size S. Parity ledger row 3.4. The owner half is
openregister `flow-task-forms` (openregister#3915 on `parity/round2`), which
ships `TaskFormReader`, `TaskFormResolver` and the completion path. Nothing
below re-implements any of it.

## 1. The declaration

- [ ] 1.1 `lib/Flow/DossiqAskPersonNode.php`: document the `form` key in the
  node's configuration, pointing at
  `lib/Service/Task/TaskDeclaration.php` for the shape rather than repeating
  it. Say in the docblock why nothing is written onto the task: the task
  carries `flowNode` and `flowRun`, which the gateway maps to `nodeId` and
  `runUuid`, and the engine resolves the form from the pinned version.
  - `@spec openspec/changes/the-ask-step-asks-for-fields/specs/case-flow-human-steps/spec.md`
- [ ] 1.2 `lib/Flow/DossiqAskPersonNode.php::validateConfig()`: refuse a
  declaration that cannot render, naming schema, field and reason. Read the
  live schema through the same service the rest of the app reads schemas
  with, and refuse rather than warn.
  - `tests/Unit/Flow/AskPersonFormDeclarationTest.php`

## 2. Proving it reaches somebody

- [ ] 2.1 `tests/Unit/Flow/AskPersonFormDeclarationTest.php`: one case per
  refusal in the spec, plus a step with no `form` that still validates.
- [ ] 2.2 `tests/e2e/ask-step-form.spec.ts`: a flow whose ask step declares a
  required field, run to the point where the assignee opens the task, fills
  the field and completes it. Assert the refusal first, with the field
  blank, so the test fails when the requirement stops being enforced.
- [ ] 2.3 Assert the pinning: a task open against version 3 keeps version 3's
  fields after version 4 is published. Without this the form silently follows
  the editable head and nobody finds out until an audit.

## 3. The seam, checked rather than assumed

- [ ] 3.1 Confirm against the installed openregister that
  `TaskFormResolver` matches an ask task's node id in the pinned graph and
  reads `node.config` for any node type, not only `openregister.user-task`.
  Record the version it was checked against in the test's docblock. If it
  turns out to be type-scoped, this change stops and openregister owns the
  next move.
