---
kind: code
depends_on: []
---

# Proposal: task-as-a-first-class-record

Round 4 discovery, cluster 53 "The task as a first-class record"
(`procest/_round4/discovery/build-plan.md` in
ConductionNL/market-intelligence, 2026-09-14). Eight candidates in the
cluster, seven of them dossiq's; buildiq took C-tasks-and-phases-33. Six
`must`, five of them dossiq's. Three passers, all three driven, proving
system Dimpact ZAC. Owner dossiq, size M. The register's reason for the
cluster carrying nothing: "no change opened beyond buildiq taking
C-tasks-and-phases-33".

## Why

The lane says it in one line about C-tasks-and-phases-14: "our tasks are
records with no form and this is the whole point of a user task".

A case type can name a task called "hoor de belanghebbende" and cannot say
what has to be filled in, who may claim it, how long it has, or what
happens when it is done. So the structured verslag a bezwaar needs becomes
a note somebody typed, and the effect of completing the task is a handler
remembering to do the next thing.

Three driven passers is the fewest in this wave, and the cluster carries
more `must` candidates than any other dossiq cluster in it. That is not a
contradiction: D6 is relevance-led, and this is a capability a Dutch case
system needs whether or not a ticketing product has it.

## What is actually there

Read against `development` at `172d364f`.

- Tasks are OpenRegister's engine, not dossiq objects. `remove-casetask`
  moved every task surface onto `useEngineTaskStore` and the
  `/api/flow-tasks/` endpoints, and the `caseTask` schema is being
  deleted. `src/manifest.json` records why in detail on the `TaskDetail`
  page and the My Work widget.
- `src/components/tasks/CaseTaskPane.vue` shows the first open task of a
  case with the engine's lifecycle buttons and confirms a completion in
  place. So C-tasks-and-phases-14 is genuinely `partial`, on one surface,
  for one task.
- `caseType.defaultAssignee` takes a group. It is the case's default, not
  a task's candidate group, and nothing lets a task be claimed.
- `lib/Service/Actions/` holds action handlers, reached through
  `ActionHandlerRegistry`. Effects exist as code; a task type declaring
  its own effects does not.
- Nothing configures a task per case type: no per-task form, group or lead
  time. Nothing binds an upload to a task. A task has no number of its own.

## The candidates

| candidate | relevance | dossiq | what it asks |
|---|---|---|---|
| C-tasks-and-phases-10 | must | no | a set of always-available acts sits on the case beside the phase-specific ones |
| C-tasks-and-phases-11 | must | partial | a task carries candidate users and candidate groups, so it can be claimed rather than assigned |
| C-tasks-and-phases-13 | must | partial | a task type carries its own declared effects, so completing it signs, sends or resumes |
| C-tasks-and-phases-14 | must | partial | a task's form is completed where you already are, in the list or on the case, without a route change |
| C-configuration-56 | must | no | each task in a case type's workflow is switched on and configured on its own |
| C-tasks-and-phases-16 | should | partial | a unit of work has its own number, its own form, its own due date and its own lock, beside the case |
| C-tasks-and-phases-2 | could | no | a file is uploaded inside a task form and binds to the task until it is completed |

Evidence verbatim from the lane
(`_round4/discovery/candidates.json`):

- C-tasks-and-phases-10, `tasks-and-phases.tsv:34`: "dimpact-zac: Process
  model (browser-walkthrough-notes.md)". Its clause: "'what may I do right
  now' as an administered list, split into always-available and
  phase-specific, is the readable half of a case model".
- C-tasks-and-phases-11, `tasks-and-phases.tsv:25`: "valtimo: Taken list
  (task-management/spec.md)". Its clause: "candidate groups is how a task
  reaches a team before it reaches a person".
- C-tasks-and-phases-13, `tasks-and-phases.tsv:14`: "dimpact-zac: Task
  completion (task-management/spec.md)". Its clause: "the effect belongs
  to the task definition rather than to the handler remembering".
- C-tasks-and-phases-14, `tasks-and-phases.tsv:10`: "valtimo: Case detail
  task panel (CaseDetail-TaskPanel.md)". The lane's note: "Two surfaces,
  one claim: the task does not take you away".
- C-configuration-56, `configuration.tsv:87`: "dimpact-zac: Task admin
  (task-management/spec.md)". Its clause: "a task's own lead time, set per
  case type, is the configuration our phase model has no field for".
- C-tasks-and-phases-16, `tasks-and-phases.tsv:31`: "osticket: Agent
  panel, Tasks (scp/tasks.php, include/class.task.php)". Its clause: "a
  task that carries its own form is how 'hoor de belanghebbende' gets a
  structured verslag".
- C-tasks-and-phases-2, `tasks-and-phases.tsv:46`: "dimpact-zac: Task form
  (task-management/spec.md)".

**D6 was answered relevance-led**, so all five dossiq `must` candidates
enter although the whole cluster has three driven passers. **D17** does
not reach it.

## What changes

- A case type configures each task in its process on its own: whether it
  runs, its form, its candidate group, its lead time, and its effects.
- A case type declares a set of always-available acts beside the acts of
  the current phase, so "what may I do right now" is one administered list
  with two halves.
- A task reaches a team before it reaches a person: it carries candidate
  groups and candidate users, it is claimed rather than assigned, and
  claiming is recorded.
- A task type declares what completing it does: sign, send, resume a
  suspended term, move the case, or nothing.
- A task's form is completed in place, on the case and in the task list,
  without a route change, for any open task and not only the first one.
- A file is uploaded inside a task form and bound to the task until it is
  completed, and then to the case.
- A task carries its own number, its own due date and its own lock, and
  they are shown wherever it is.

## Ownership

The task record is **OpenRegister's**, and dossiq owns none of it. This
was settled by `remove-casetask`: dossiq deleted its own task schema and
reads the engine. So this change is mostly a declaration and a surface,
and the mechanism is named where it belongs.

| half | app | artefact |
|---|---|---|
| the task record, its number, its due date and its lock | openregister | the flow task engine, shipped, read through `/api/flow-tasks/` |
| the task's own form and its fields | openregister | `flow-task-forms`, an existing change, named by the register for row 3.4 |
| the inbox filters over tasks | openregister | the flow-tasks inbox filters, shipped, consumed by dossiq `task-search-fields` |
| the task's default handler | openregister | the engine task, shipped, consumed by dossiq `task-defaults-to-case-handler` |
| a row action inside a list | nextcloud-vue | `working-list-row-actions`, named by the register; `CnObjectListWidget` has no row actions today, which is why `CaseTaskPane.vue` exists as an interim |

What dossiq builds: the per-case-type task configuration, the
always-available acts declaration, the effects declaration, the in-place
completion surfaces, and the binding of an upload to a task.

### Needs a change in openregister

Two halves have no artefact:

- **Candidate users and groups on an engine task, with a claim act**
  (C-tasks-and-phases-11). The engine assigns; nothing lets a task sit
  with a team until somebody takes it. No slug in the register's
  `changes_by_repo` covers it.
- **A task's own number and its own lock** (C-tasks-and-phases-16). The
  engine has neither, and `remove-casetask` records a related consequence
  already: engine tasks have no unified-search provider, because their
  rows live in their own table and no deep-link shape addresses them.

A follow-up lane should open both in openregister. Until then dossiq
declares the candidate group on the case type and the engine ignores it,
which the requirement states rather than hides.

## ADRs

- Company ADR-011: search OpenRegister before implementing a utility. This
  change adds no task store, no task id and no task lock to dossiq.
- Company ADR-050: the error envelope is `{message, error}`. A task
  completed without a required form field is refused with the field named.
- Company ADR-102: config absence fails closed with a status. A declared
  task effect whose handler cannot be resolved refuses the completion
  rather than completing without the effect.
- dossiq `openspec/specs/task-management/spec.md` and
  `openspec/specs/process-step-configuration/spec.md` are what this
  extends.

## Capabilities

- Modified: `task-management`: a task is claimed, completed in place,
  carries its own form and effects, and binds an upload.
- Modified: `process-step-configuration`: each task in a case type is
  configured on its own, and always-available acts are declared beside the
  phase-specific ones.

## Impact

`caseType.workflowDefinition` and the task declaration on it,
`lib/Service/Actions/` and `ActionHandlerRegistry`,
`src/components/tasks/CaseTaskPane.vue`, the task list, the engine task
client, Dutch and English strings.

## Out of scope

- The task record itself. openregister, named above.
- One personal queue over every task. dossiq `one-personal-queue`, this
  wave.
- Phase acts and the phase vocabulary. Cluster 37 and dossiq
  `case-type-publish-validation`.
- The registration form a citizen fills in. buildiq, rows 3.12 and 11.4.
