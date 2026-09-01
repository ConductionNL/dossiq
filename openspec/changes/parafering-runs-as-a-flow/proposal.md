# Parafering can run as a flow, without losing the mandate record

## Why

dossiq#1582 projected each approval route onto a flow of `dossiq.askPerson`
nodes, so the route could become a flow. Sizing the switch showed the
projection was not yet a drop-in replacement, for a reason the projection could
not see.

`dossiq.askPerson` raises a generic TASK. Parafering reads `parafeeractie`, and
the two are not interchangeable:

    Endorsement Action  proposal, step, actor, actorType, onBehalfOf,
                        action, comment, advice, mandate
    Task                title, description, status, case, assignee, dueDate,
                        priority, workflowStepId, checklist, flowRun, flowNode

`onBehalfOf` and `mandate` say who signed on whose behalf and under which
mandate. That is administrative-law record, not a UI detail. Enabling a
projection built on askPerson would have put generic tasks in approvers'
queues, left the parafering screens empty, and stopped recording the mandate
chain — a loss of record dressed as an engine change.

## What changes

A `dossiq.askParaaf` node that raises a `parafeeractie` and waits for it, and a
migrator that emits it instead of `askPerson`.

`parafeeractie` gains `flowRun` and `flowNode`. Resuming has to name the NODE,
not just the run: a run holds one awaiting slot per node and cannot say which
of them a paraaf answers. `askPerson` records those two on its task for exactly
this reason; a paraaf now can too.

The node also carries the route's OWN step number rather than its position in
the chain, because the parafering screens read `step` and it must mean what the
route meant.

## What this change does NOT do

It does not enable the projections and does not retire anything. The route
still drives parafering through `BesluitvormingParafeerService`.

The remaining step, deliberately separate, is a dual-path service: a voorstel
carrying a `routeSnapshot` and no flow run finishes the way it started, while
new ones start a run. A hard cutover strands whatever is mid-parafering, and
the dev instance cannot show that — it holds zero voorstellen. Production can.

## Correction, 2026-09-01

The node first shipped creating the `parafeeractie` up front, as a standing
request. That could not work and the tests could not see it.

`parafeeractie` declares `action` among its required properties and
OpenRegister runs hard validation by default, so a paraaf raised without one is
rejected on save. The node's unit tests passed because their fake accepted
whatever it was handed; teaching that fake the schema's required properties
turned five of eighteen red at once.

The schema was right and the node was wrong. A `parafeeractie` is the record of
a sign-off somebody gave, not a request that they give one, and no enum value
should be invented to let a blank one stand for "awaiting".

So the node records the ask in the run's own awaiting slot, which already
carries the assignee OpenRegister's resume guard consults, and creates nothing.
The approver signs through the ordinary parafering surfaces, which create the
parafeeractie with its action.
