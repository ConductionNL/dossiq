# An approval route is a flow, not a schema

## Why

An approval route is a sequence of manual sign-offs that must be taken before a
decision may be attached to a case. That is a flow with a person at each step.
It was modelled instead as a schema, twice: dossiq's `parafeerroute` and
decidiq's `ApprovalRoute`, the latter added only last week in decidiq#1028.

Two half-engines, no editor for either, and a settings page in dossiq whose only
job was to author rows that a bespoke service then walked. Meanwhile
OpenRegister's flow store already has the engine, the editor, the run history
and the node that asks a person something and waits.

decidiq#1028 was mine and it was the wrong shape. This supersedes it.

## What this change does

Projects every stored route onto a flow, the same way workflow definitions were
projected in #1542, and for the same reason.

Each step becomes a `dossiq.askPerson` node rather than a bare
`openregister.awaitSignal`. That choice matters to the approver: askPerson
raises a real dossiq TASK against them and waits for the answer in one node, so
the step arrives in the work queue they already read. A raw await signal waits
for an answer nobody was asked for.

The chain ends at `dossiq.requestDecision`, because that is what an approval
route is for. The steps are the sign-offs; the decision is what may be attached
to the case once they are done. The case schema already carries `decisions` as
a relation to decidiq Decision objects, so the destination exists.

## What this change does NOT do

It does not retire the route, the schema or the settings page. The projection
arrives DISABLED, because the route still drives parafering through
`BesluitvormingParafeerService`, and two live copies would ask every approver
twice. Retiring is the next change, once the projections have been verified
against real routes.

Saying that plainly matters, because the visible symptom this work exists to fix
is a settings menu with too many entries, and this change removes none of them.
It makes removing one possible.

## Also here

`WorkflowTemplateFlowMigrator` and this migrator had 133 lines of identical
machinery: resolve FlowService without hard-depending on it, index projected
flows by provenance marker, write one without letting a single failure abort the
rest. That is now the `ProjectsOntoFlows` trait. Three migrators were converging
on the same code, and copies drift.
