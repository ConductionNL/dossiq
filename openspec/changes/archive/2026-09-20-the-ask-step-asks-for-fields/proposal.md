---
kind: code
depends_on: [task-as-a-first-class-record]
---

# Proposal: the-ask-step-asks-for-fields

Parity ledger row 3.4, "user tasks with a form". The ledger rates dossiq
partial and gives the reason as `lib/Flow/DossiqAskPersonNode.php` plus
`#TaskDetail`, a task without a form. The owner half is openregister's
`flow-task-forms`, merged on `parity/round2` (openregister#3915), and
`competitor-parity-2026-09` already names dossiq's half in one line: replace
the ask step's bare task with a step that declares the fields.

## Why

A flow asks somebody to hear a belanghebbende, and the step says one thing:
the question. Whatever the handler writes down lands in a comment, a note, or
nowhere. The next step reads the case and finds no verslag, because no field
ever asked for one.

The same instance already has the better behaviour on the other path. A task
created by a status transition carries a declared form: `task.form` in the
case type's step block, written to the engine task as `metadata.form` and
enforced on completion. A handler filling that task is told which field is
missing before the round trip.

So one product asks for evidence and records it, and the same product, one
step to the left in the same flow, asks a question and keeps nothing.

## What is actually there

Read against `parity/round2` at `c3bdf65d`. The ledger's reason is half
right, and the correction is what makes this change small.

- `lib/Service/Task/TaskDeclaration.php` already reads a `form` block in
  OpenRegister's own shape and writes it verbatim to `metadata.form`.
- `lib/Service/Task/CaseTaskActions.php::requiredFields` refuses a completion
  that leaves a declared required field blank, and `0` and `false` count as
  answers.
- `src/components/tasks/CaseTaskPane.vue` renders the form through
  `taskFormOf()` and refuses to render one whose state is not `ready`.
- `lib/Service/Task/EngineTaskGateway.php` maps `flowNode` to `nodeId` and
  `flowRun` to `runUuid`, so every ask task already tells the engine which
  node of which pinned version created it.
- openregister's `TaskFormResolver` resolves a run-carrying task's form by
  matching that node id in the pinned graph and reading `node.config`. It
  never asks what type the node is.

What is missing is one thing: `DossiqAskPersonNode::buildTask` builds a task
from `question`, `details`, `assignee` and `dueInDays`, and
`validateConfig()` accepts nothing else. An author cannot declare a field,
so the resolver finds none.

## What changes

- The `dossiq.askPerson` step configuration accepts a `form` block in
  OpenRegister's shape, the same one `TaskDeclaration` already documents:
  `kind: fields` naming the subject schema and an ordered
  `[{field, required}]` list, or `kind: external` naming a bound Nextcloud
  Forms form.
- `validateConfig()` refuses a declaration the performer could not fill:
  a field that is not a property of the subject schema, a readOnly field, an
  invisible field, or a `kind` outside the two. The refusal names the schema,
  the field and the reason, so it lands on the author while they are saving
  the flow.
- The node writes nothing new onto the task. The task already carries its
  node id and its run, so the form resolves from the version the run is
  pinned to, and editing the flow afterwards changes no open task's form.
- A step that declares no `form` behaves exactly as it does today.

## What this change does not do

It does not replace `dossiq.askPerson` with openregister's
`openregister.user-task` node, which is the line the umbrella wrote. The ask
node suspends and creates the task in one node on purpose: a run holds one
awaiting slot per node, so a `createTask` step before an `await-signal` step
cannot name the node that will block. That reasoning is recorded in the
node's own docblock and is not relitigated here. Adopting the engine's node
means answering it first, and the form does not have to wait for that.

## Capabilities

### Modified Capabilities

- `case-flow-human-steps`: the ask step gains a declared form, resolved and
  enforced by the engine rather than by a second dossiq vocabulary.

## Impact

- **PHP**: `lib/Flow/DossiqAskPersonNode.php` (`validateConfig`, the config
  documentation), and a unit test per refusal.
- **Schemas**: none. The declaration lives in the flow definition, which is
  an openregister object.
- **Frontend**: none. `CaseTaskPane` and the task page already render a form
  the engine resolves.
- **Backwards compatible**: a step with no `form` key is unchanged, and every
  open task keeps the form its pinned version declared.
