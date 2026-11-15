# Design: task-as-a-first-class-record

## D-1. dossiq declares, the engine performs

`remove-casetask` settled the ownership: dossiq has no task store, and
every task surface reads OpenRegister's engine through
`/api/flow-tasks/`. Nothing in this change moves that back.

So every requirement here is one of two shapes. Either it is a declaration
on the case type, which dossiq owns because the case type is dossiq's
file, or it is a surface that renders what the engine answers. Where the
engine cannot yet answer, the requirement says so and names the missing
openregister half rather than building a dossiq shadow of it.

A dossiq-side task number or task lock would be exactly the schema nobody
writes to that `remove-casetask` spent a change deleting.

## D-2. Per-task configuration lives on the case type, one task at a time

Dimpact configures each task on its own
(`_round4/discovery/candidates.json`, C-configuration-56,
`configuration.tsv:87`), and the clause names the missing field: "a task's
own lead time, set per case type, is the configuration our phase model has
no field for".

So the case type's workflow declaration grows a per-task block: on or off,
the form, the candidate group, the lead time and the effects. It is one
block per task rather than five parallel maps, because five maps keyed by
task name drift the moment a task is renamed.

A task declared with a form the case type cannot resolve refuses to
publish, the same rule `case-type-publish-validation` already applies.

## D-3. Always-available acts are a second list, not a phase with no order

"What may I do right now" has two halves: what this phase allows, and what
is always allowed (`tasks-and-phases.tsv:34`). Modelling the second as a
phase that is always active would make it appear in the phase strip, in
the progress figure and in the term calculation, all of which would be
wrong.

So it is its own declared list on the case type, rendered beside the
phase's acts and marked as such. It is the same idea as the lifecycle menu
in `lifecycle-acts-on-the-case`, one level down: one place that answers
what is possible, with the reason when something is not.

## D-4. A task reaches a team first, and claiming is the act

Valtimo's candidate groups are how a task reaches a team before a person
(`tasks-and-phases.tsv:25`). Assigning a task to a person who is on leave
is how a term is missed; letting it sit with a team until somebody takes it
is how it is not.

The declaration is dossiq's, on the case type. The claim is the engine's,
and the engine has neither candidate lists nor a claim act today. So the
requirement declares the candidate group, renders the claim affordance
when the engine answers one, and states plainly that the engine ignores
the declaration until openregister builds it. A surface that pretends to
claim and silently assigns would be worse than none.

## D-5. The effect belongs to the task definition, not to the handler

Dimpact puts the effects on the task definition
(`tasks-and-phases.tsv:14`). dossiq has `lib/Service/Actions/` and
`ActionHandlerRegistry`, so the handlers exist; what is missing is that a
task type names which of them completing it runs.

So a task declares its effects as a list of named handlers from the
registry. Publishing refuses a task naming a handler the registry does not
have, and completing refuses when a declared handler cannot be resolved at
run time, per ADR-102. An effect that silently did not run is the failure
this design is avoiding, and it is the same shape as a requirement with no
trigger.

Resuming a suspended term is one of the effects, which is how the pause in
`phase-terms-and-the-internal-target` is lifted by finishing the task that
asked for the aanvulling, rather than by somebody remembering.

## D-6. In place means any open task, on both surfaces

`CaseTaskPane.vue` already completes the first open task on the case page,
and its own note says why it is a component rather than a configured
widget: `CnObjectListWidget` has no row actions and no lifecycle column,
so a button cannot be put inside a row from configuration.

So the requirement is about reach, not about a new component: every open
task, on the case and in the task list, without a route change. The list
half waits on nextcloud-vue's row actions, and the proposal names it.
The case half is the existing pane, widened from the first open task to
all of them.

A completion that needs a form shows the form in place. A required field
left empty refuses with the field named, per ADR-050, rather than
completing a task whose verslag is blank.

## D-7. A file bound to a task moves to the case when the task closes

Dimpact binds the upload to the task form until it is completed
(`tasks-and-phases.tsv:46`). Before completion the file belongs to a piece
of work in progress; after it, it is evidence on the case.

So the upload is held against the task, is removable while the task is
open, and becomes a document on the case when the task completes,
carrying which task produced it. A file that stayed invisible on a closed
task would be a document the archive never sees.
