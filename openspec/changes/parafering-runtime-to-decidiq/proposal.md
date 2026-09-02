---
kind: code
---

# Proposal: parafering-runtime-to-decidiq

## Summary

The parafering runtime leaves dossiq completely. dossiq raises a voorstel's
sign-off chain in the decision app, waits for the conclusion event, and keeps
only case-data records — the same shape it already uses for decisions. The
local engine retires: no facade, per the approval-consolidation precedent.

## Motivation

`parafering-to-decidiq` moved the route TEMPLATE and deferred its Task 5 with a
precise reason — dossiq's pipeline owned a status vocabulary, a return
notification, accordering effects and mandate validation the decision app's
engine did not, and replacing it meant reproducing all four or losing them.
The decidiq counterpart (`parafering-route-runtime`) has now absorbed all four
into the ApprovalRoute engine and announces a conclusion from every concluding
path. So the deferral is over: the runtime moves, and dossiq delegates
parafering exactly like it delegates decisions.

## Affected Projects

- [x] Project: `dossiq` — this change.
- [x] Project: `decidiq` — the counterpart (`parafering-route-runtime`), which
  MUST merge FIRST. dossiq deletes its local engine on the assumption decidiq's
  engine already holds the runtime; merged the other way round, a voorstel
  entering parafering has nothing anywhere to run its chain.

## Scope

### What retires

`BesluitvormingParafeerService` (activate + `handleParaafAction` + local
advancement), `ParafeerActieService` (the record-and-advance sign-off engine)
and its `ParafeerStepGuard` / `ParaferingActionMapper` helpers, the
`ParafeerActieController` and its `/api/parafeer-actie` routes, and the whole
dossiq-local flow projection of parafering — `ParaferingFlowGateway` (the
per-route cutover flag), `EndorsementRouteFlowMigrator`, `DossiqAskParaafNode`,
`DossiqSetVoorstelStatusNode`, `ParaafFlowLinkage`, `ParaafResumeListener` and
the `MigrateApprovalRoutesToFlowsCommand`. A dossiq-local flow engine running
alongside the decision app's is the two-engines hazard the whole move exists to
close, so it goes as one.

No facade stands in for the retired services (the approval-consolidation
precedent): a caller still wanting local advancement fails at compile time
rather than silently running a second engine. The class-catching
`LocalParaferingRuntimeTest` pins the absence.

The FRONT-END sign-off surface retires with it — `ParafeerActieDialog`,
`SkipStepDialog`, `DelegateSelectorField`, the client-side `parafeerEngine.js`,
and their triggers on `VoorstelDetail`. An approver signs in the decision app
(each active step is projected onto their task queue there); dossiq's page now
shows the recorded history, read straight from OpenRegister's object API.

### What replaces it

- **The raise** (`ParaferingRaiseService`): resolves the route locally,
  refuses a voorstel that cannot be routed, hands route AND subject to the
  decision app, records `approvalRouteId` on the voorstel. It FAILS CLOSED —
  with no local runtime to fall back to, an install without the decision app
  keeps its voorstellen out of parafering rather than parking them with no
  engine.
- **The conclusion** (`ParaferingConclusionService` + `ParaferingConcludedListener`):
  consumes the decision app's `ApprovalRouteConcludedEvent`, filtered to
  `sourceApp: dossiq`, and projects the outcome onto the case — one
  `parafeeractie` per sign-off (actor, onBehalfOf, mandate, comment, advice),
  the final status, the steller's notification, the accordering signature, and
  the append-only audit trail. It records and never decides — the parafering
  twin of `BesluitMaterialisationService`. The frozen `procest.parafering.*`
  audit prefix survives: each recorded sign-off still raises a
  `ParafeerTransitionEvent`, so `ParaferingAuditListener` keeps writing the
  same legal trail.
- **The in-flight migration** (`RaiseInFlightParaferingenInDecidiq`): re-raises
  every voorstel still `in_parafering` / `ter_accordering` so the decision app
  has a chain to finish, registered in `info.xml` post-migration after the
  route-holding step. It states its honest cost: the chain resumes from the
  start in the decision app, because a partial local history the retired
  runtime never sent cannot be imported.

## Risks

- 🔴 **Merge order.** dossiq breaks without decidiq's engine absorbing first.
  Both PR bodies say so; sequence the decidiq PR ahead.
- 🔴 **The case record must survive the move.** onBehalfOf and mandate are
  administrative-law record, not UI detail; the conclusion recorder preserves
  them and is mutation-checked against dropping either, against double-recording
  a replayed sign-off, and against re-recording an already-concluded voorstel.
- ⚠️ **In-flight voorstellen restart their chain in the decision app.** Stated
  in the migration and its docblock rather than hidden; the dev instance holds
  zero voorstellen and cannot surface it, production can.
