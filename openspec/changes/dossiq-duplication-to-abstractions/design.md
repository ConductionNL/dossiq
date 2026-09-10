# Design: moving eleven duplicated clusters onto OpenRegister

## D-1. The migration shape, identical for every cluster

Every cluster follows the same four steps. A cluster that cannot finish step 1
does not start step 2, and no step is skipped because a cluster looks small.

1. **Pin.** Write tests against *current* behaviour before anything moves, and
   mutation-check each one. A migration regression in this codebase does not
   throw; it returns plausible wrong data with HTTP 200. The tenancy change
   already establishes this and found three middlewares with no tests at all.
2. **Map.** For each property of each dossiq schema, name the OpenRegister
   field it becomes, or state that it is dropped and why. A property with
   neither is a blocker, not a rounding error. The map is a table in the
   cluster's own change, reviewed before code.
3. **Dual-run.** Write to both stores, read from the new one. Ship a config
   flag that falls back to the old store, so a bad read is one setting away
   from recovery rather than one deploy.
4. **Remove.** Delete the schema, the surface, and the slug. Done means
   `git grep <slug> lib/ src/ tests/` returns nothing, not that the code
   stopped calling it.

## D-2. Why the task spine goes first

Advice requests and inspection runs are both "somebody must do a thing, answer
some questions, and it has a deadline". That is a task with a form. Migrating
them before `Task` lands means writing the same adapter twice and throwing it
away.

`caseTask` is also the only cluster where the target is already finished and
already names dossiq. Five of the six OpenRegister changes that build
`Task` are complete; `flow-task-forms` is 17/20 and its three open tasks are
`nextcloud-vue` UI work, not server work.

## D-3. The dossiq task detail page STAYS

Ruben's constraint, and it is the right one. Moving the record to
OpenRegister's `Task` does not move the *interface* there. Dossiq keeps
`/tasks/:id` and keeps a dossiq-specific surface on it: the case card, the
notes and appointment leaves, the lifecycle buttons, the dossiq vocabulary.

What changes underneath is which store the page reads. The page moves from
`register: dossiq, schema: caseTask` to OpenRegister's task API. The manifest
page id, the route and the deep links do not change, so notification links and
bookmarks survive.

## D-4. NC Tasks is a view, not the store

This is the part of Ruben's question that already has an answer in
OpenRegister, and it is worth restating so nobody rebuilds it.

`TaskCalendarProjector` writes each task out as a VTODO carrying `SUMMARY`,
`DUE`, `PRIORITY`, `STATUS`, plus `X-OPENREGISTER-TASK` and
`X-OPENREGISTER-TASK-ASSIGNEE` so the row stays addressable. The Tasks app and
every CalDAV client then sees it for free.

Ticking it off comes back through `TaskVtodoWriteBackGate`, which describes
itself as *"THE one path from a calendar back into the engine"*. What crosses
is a `(task_uuid, requested_verb, actor)` triple and nothing else: never a
state, never a field value. `STATUS:COMPLETED` can only *request* `complete`;
the engine decides whether that verb is legal through the same authorization
every other caller gets, and refuses visibly.

So dossiq writes no CalDAV code. It writes tasks to the engine, and the
projection is somebody else's finished problem.

## D-5. The library gaps, lifted once

Four of the six existing dossiq changes carry tasks marked
`[blocked: nextcloud-vue …]`. Collected:

| Blocker | Blocks | Shape of the fix |
|---|---|---|
| `CnObjectListWidget` renders a `$ref` as its raw uuid | documents-on-the-case 2.2 | A reference column that resolves to a label |
| `CnIndexPage` column `link` cannot name a route | contacts-domain 3.6 | Let a column declare a route target |
| `actionsDispatcher.js` cannot dispatch a declared action | documents-on-the-case 3.3 | Dispatch by name from the manifest |
| `cnFormFieldRenderer` has no `file` type | the task form's upload | Add `field.type === 'file'` |

All four are additive, and all four are the same disease as the clusters
themselves: a gap below every app, worked around locally N times. They land as
one `nextcloud-vue` change ahead of the clusters that need them.

Note `nextcloud-vue`'s `development` carries a real ruleset, so that merge
needs `--admin`, unlike dossiq's advisory branch.

## D-6. What "the case" is, and why `case` is not on the list

OpenRegister's `CaseItem` docblock settles this:

> There is no case entity. `object_uuid` + `register_id` + `schema_id` is the
> same triple a flow run carries as `subject_*` and a task carries as
> `object_*`: the zaak, the bezwaar, the vergunning IS the case.

So dossiq's `case` schema is correct as it stands and is absent from this
programme. `CaseItem` is a *plan item*, the occurrence in a case plan that
decided some work should exist, and it is the counterpart for
`workflowTemplate` and `casePlanState`, not for `case`.

## D-7. Dutch law stays in dossiq

The mechanical test was: does OpenRegister ship an entity for this exact
concept, and can the file be pointed at. `bezwaar`, `beroep`,
`objectionProceeding`, `handhavingsactie`, `hearingSession`, `complaint`,
`lhsMatrix` and their kin fail that test and stay. They encode Awb procedure,
not platform mechanics, and moving them would push Dutch administrative law
into a general-purpose register.

The one nuance: several of those schemas *contain* a duplicated concept even
though they are not one. `bacAdviceRequest` is a bezwaar-specific advice
request, and the advice cluster absorbs its request/response mechanics while
leaving its Awb-specific fields on a dossiq schema. That split is drawn per
schema in the advice change, not here.

## D-8. Risks

**The register is a live store.** Every schema named here has rows in running
instances. Step 4 of D-1 is the only step that destroys data, which is why it
is last and why dual-run precedes it.

**Slug renames have nine hiding places.** A retired slug hides in seed data,
demo data, e2e fixtures, `ci-seed.sh`, the manifest, the registry, deep links,
stored object payloads and other apps' duck-typed lookups. The fleet has been
caught by this repeatedly. Each cluster's Remove step greps case-insensitively
across the whole repo, not just the obvious directories.

**Other apps read dossiq's schemas by name.** `isInstalled('dossiq')` and
`class_exists` lookups are duck-typed: pointing one at a name nothing answers
to makes the integration silently no-op rather than error. Before removing a
slug, grep the sibling checkouts, not only this one.

**The programme is long and the branch is shared.** Each cluster is its own
branch and its own PR into `development`. Nothing waits for the whole
programme to finish.
