# Remove the `caseTask` schema

## Why

`caseTask` is the first cluster of `dossiq-duplication-to-abstractions`, and
the only one graded **field-verified**: all fifteen of its properties map onto
OpenRegister's `Task`, and `state` and `priority` are literally the same
vocabularies, so nothing translates.

Three of the four migration steps in that programme's D-1 are done and
merged:

| Step | State |
|---|---|
| 1 Pin | done. The three named targets already had tests; the gateway, backfill and listener added 28 more, the load-bearing ones mutation-checked |
| 2 Map | done. 12 of 15 properties are 1:1 |
| 3a Dual-run, WRITE | done and verified live. 33 tasks in `oc_openregister_tasks`, idempotent on re-run |
| 3b Dual-run, READ | the store exists (`useEngineTaskStore`); no surface consumes it |
| 4 Remove | this change |

The flow-resume path is already migrated, and that was the load-bearing part:
`TaskCompletionResumeListener` listens to the engine's `TaskTerminalEvent`
rather than to an object write, so removing the schema cannot silently wedge a
run that waits on a person.

## What makes this change large

**70 files reference the slug**, and two of them are not repointing jobs but
rewrites.

`Tasks` (`type: index`) and `TaskDetail` (`type: detail`) are generic
manifest-driven pages. Both work by binding a register and a schema and
letting the platform render. **The engine is not an OpenRegister object**, so
neither page type can be pointed at it: both become `type: custom` with a
dossiq component behind them.

That is also the constraint this change must respect. The dossiq task detail
page STAYS, at the same route, with its case card, its notes and appointment
leaves and its lifecycle buttons. What changes is the store behind it, not the
surface.

## Why this is one change and not five

Because there is no fallback once the schema is gone, and a half-migrated
surface reads from a store that no longer has rows. The register object and
the engine row must stop coexisting at a single moment.

The alternative — migrate surfaces one at a time while both stores are live —
was considered and rejected in this programme's own sequencing discussion: two
stores disagreeing about which page you are looking at is more confusing than
one cutover, and the dual-run has already served its purpose by proving the
data maps.

## The risk, stated plainly

**There is no fallback store after step 4.** If an engine read is wrong there
is no `caseTask` row left to compare against. The mitigations are:

- the backfill is idempotent and re-runnable while the schema still exists,
  so the comparison can be made as often as needed BEFORE this change lands
- every surface gets an e2e test before its read moves
- the schema is deleted LAST, in its own commit, after every surface is green

`completedDate` is lost for backfilled tasks, and that is known and filed
(openregister#3575): `TaskBuilder` reads no `completedAt`, because the engine
sets it through the complete verb. Thirteen of dossiq's thirty-three tasks are
completed and keep their state but not their date. Reconcile from the register
row before deleting the schema, or accept the loss deliberately.
