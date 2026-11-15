# Tasks: the-ask-step-asks-for-fields

Tier: MVP. Kind: code. Size S. Parity ledger row 3.4. The owner half is
openregister `flow-task-forms` (openregister#3915 on `parity/round2`), which
ships `TaskFormReader`, `TaskFormResolver` and the completion path. Nothing
below re-implements any of it.

## 1. The declaration

- [x] 1.1 `lib/Flow/DossiqAskPersonNode.php`: document the `form` key in the
  node's configuration, pointing at
  `lib/Service/Task/TaskDeclaration.php` for the shape rather than repeating
  it. Say in the docblock why nothing is written onto the task: the task
  carries `flowNode` and `flowRun`, which the gateway maps to `nodeId` and
  `runUuid`, and the engine resolves the form from the pinned version.
  - `@spec openspec/changes/the-ask-step-asks-for-fields/specs/case-flow-human-steps/spec.md`
  - 🔴 THE SHAPE IS NOT THE ONE THE PROPOSAL NAMED, and this is the change's
    main finding. The proposal said the step accepts a `form` block "in
    OpenRegister's shape, the same one `TaskDeclaration` already documents".
    It does not. `TaskDeclaration` writes a NESTED block to the task's
    `metadata.form`, which the engine reads through
    `TaskFormReader::fromRecord()`. A FLOW NODE carries no such block:
    `TaskFormResolver::declarationOf()` reads `node['config']` through
    `TaskFormReader::fromConfig()`, and that method reads six FLAT keys —
    `formKind`, `formSchema`, `formAction`, `formFields`, `formId`,
    `formRequireChecklist`. A nested `form` block on an ask step would leave
    `formKind` absent, produce a declaration whose `hasForm()` is false, and
    hand the assignee a task with no fields and no error anywhere. Measured
    against openregister `parity/round2` before anything was written, because
    this was the one thing that could not be guessed from the transition path.
- [x] 1.2 `lib/Flow/DossiqAskPersonNode.php::validateConfig()`: refuse a
  declaration that cannot render, naming schema, field and reason. Read the
  live schema through the same service the rest of the app reads schemas
  with, and refuse rather than warn.
  - `tests/Unit/Flow/AskPersonFormDeclarationTest.php`
  - The refusals are CONSUMED, not rebuilt. `TaskFormReader::fromConfig()`
    refuses the shape and `::validate()` refuses the fields, both naming the
    schema, the field and the reason. dossiq forwards the whole config and
    lets the messages travel unchanged: a dossiq paraphrase would be a second
    opinion about the same schema, and an author who reads one wording here
    and another in openregister can search for neither.
  - The reader is injected as a NULLABLE LAST parameter, so the three existing
    construction sites are untouched. A step that declares a form on an
    instance with no reader is REFUSED rather than accepted unchecked: an
    unvalidated declaration lands on the performer, who can neither fill the
    field nor skip it.

## 2. Proving it reaches somebody

- [x] 2.1 `tests/Unit/Flow/AskPersonFormDeclarationTest.php`: one case per
  refusal in the spec, plus a step with no `form` that still validates.
  - 9 tests. They drive DOSSIQ'S HALF: a step with no form key never reaches
    the reader, any single form key does, an empty key is not a declaration,
    both refusals travel unchanged, and no reader plus a form is a refusal.
    The field-level rules are openregister's and are asserted in its own
    `tests/Unit/Service/Task/TaskFormReaderTest.php`; re-asserting them here
    would need a fake reader, and a fake of somebody else's rules can only
    pass. Mutation checked: making `validateForm()` return unconditionally
    reddens five assertions, none of them a setup line.
  - `tests/Stubs/Service/Task/TaskFormReader.php` and `TaskForm.php` are new.
    Every stub method THROWS, so a test that leaned on stub behaviour fails
    loudly rather than passing on rules nothing ships.
- [x] 2.2 `tests/e2e/ask-step-form.spec.ts`: a flow whose ask step declares a
  required field, run to the point where the assignee opens the task, fills
  the field and completes it. Assert the refusal first, with the field
  blank, so the test fails when the requirement stops being enforced.
  - The refusals are driven first, and the nested-block trap has a case of its
    own. The completion half reads the published flow's own config rather than
    driving a run: this phase runs no Playwright, and a run needs the case flow
    ENABLED, which the shared instance must never be (see the `live-journeys`
    project).
- [ ] 2.3 Assert the pinning: a task open against version 3 keeps version 3's
  fields after version 4 is published. Without this the form silently follows
  the editable head and nobody finds out until an audit.
  - NOT ASSERTED HERE, and named rather than skipped. The pin is
    `TaskFormResolver`'s: it resolves through `FlowPublishedGraph` for the
    version the run is pinned to and falls back to nothing, never to the head.
    dossiq adds no code on that path — `buildTask()` is unchanged and writes
    no form onto the task — so there is nothing in this repository a dossiq
    test could break to make the assertion fail. Driving it needs a live run
    across two published versions, which needs the case flow enabled.
    openregister owns that test.

## 3. The seam, checked rather than assumed

- [x] 3.1 Confirm against the installed openregister that
  `TaskFormResolver` matches an ask task's node id in the pinned graph and
  reads `node.config` for any node type, not only `openregister.user-task`.
  Record the version it was checked against in the test's docblock. If it
  turns out to be type-scoped, this change stops and openregister owns the
  next move.
  - CONFIRMED. openregister `parity/round2`,
    `lib/Service/Task/TaskFormResolver.php:197-201`: the resolver matches on
    `$node['id'] === $task->getNodeId()` and reads `$node['config']`. It never
    asks what TYPE the node is. Recorded in the unit test's docblock.
