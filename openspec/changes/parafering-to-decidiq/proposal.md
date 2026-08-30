---
kind: code
---

# Proposal: parafering-to-decidiq

## Summary

Move the parafeerroute — the reusable definition of who signs a voorstel, in
what order — to decidiq's `ApprovalRoute`, and read it from there. The runtime
chain state stays in dossiq for now, deliberately.

## Motivation

Routing a document past a sequence of officials for approval is governance, and
governance is decidiq's. decidiq's `approval-routes` change built the target and
named this app as the consumer it was built for.

`parafeerroute` has carried `DEPRECATED (migrate-parafering-to-or-approval-workflow)`
in its schema description for months. **It is not deprecated.** Measured
2026-08-30: 15 PHP files, 7 frontend files, 4 routes and 4 test files, roughly
1,810 lines of engine across six services. The archive says why — that change was
*"archived prematurely; implementation not present on development"* and reverted.
A banner that describes an intention reads, to anyone who did not check, as a
state.

## Affected Projects

- [x] Project: `dossiq` — this change.
- [ ] Project: `decidiq` — done. `approval-routes` shipped the engine;
  `approval-route-events` shipped the command seam. Nothing further is asked.

## Scope

### In Scope

1. **A delegation service** that dispatches decidiq's
   `ApprovalRouteRequestedEvent` to hold a route, guarded by `class_exists`
   across both namespace spellings, failing closed when the app is absent.
2. **A migration** turning each local `parafeerroute` into an `ApprovalRoute`
   with `sourceApp: dossiq`, and recording the returned id on the local row.
3. **Activation sends the route and starts the chain there.**
   `BesluitvormingParafeerService::activate()` resolves the route locally, takes
   its snapshot from those steps, and dispatches them to decidiq naming the
   voorstel as subject — so decidiq holds the route AND materialises the
   sign-off chain, which is what makes "all pending approvals across apps" a
   question anyone can ask.
4. **A refusal that is currently missing.** See below.

### Why there is no read path

An earlier draft had activation READ the route back from decidiq, preferring it
over the local copy. That was wrong on two counts and is recorded here because
the reasoning generalises.

It needed a cross-app read seam that does not exist. ADR-066 lifted the ADR-041
moratorium only for render-and-read leaf providers via a typed collect-event;
there is no such event for approval routes, so the draft would have invented
one, or reached into decidiq's register, which ADR-022 forbids.

And it was unnecessary. dossiq HAS the steps at activation — it just read them.
Sending them is strictly better than sending them, forgetting them, and reading
them back.

### Out of Scope, and why

**The runtime chain state.** `parafeeractie`, `currentStep`, `routeSnapshot`
and the whole action pipeline stay in dossiq.

This is the same split the committee migration made, and for a better reason
than caution. dossiq's pipeline does four things decidiq's engine does not:
it owns a status vocabulary (`in_parafering` / `ter_accordering` /
`geaccordeerd` / `teruggestuurd`), it notifies the steller on a return, it
applies accordering effects that activate besluitvorming, and it validates
mandates for delegated signing. Replacing the engine means reproducing all four
or losing them. That is its own change, with parity tests, not a rider on a
template move.

What makes the split clean is that the two barely touch: a running voorstel
reads `proposal.routeSnapshot`, a frozen copy taken when it entered parafering.
The template has exactly ONE reader — the snapshot-taker in `activate()` — so
moving the template changes one call site rather than the pipeline.

**Retiring the local schema.** Deferred, as with committees. decidiq is an
optional runtime dependency, so the fallback is permanent until it is not.

## A dead end found while reading `activate()`

`activate()` looks up the default parafeerroute for the voorstel's caseType. When
it finds none it sets `routeSnapshot: []` and carries on, writing
`status: in_parafering, currentStep: 1`.

Every subsequent action then fails. `resolveCurrentStep()` decodes the empty
snapshot, finds no step matching `currentStep`, and throws
`Current step not found in route snapshot` — a 400 that names the snapshot while
the actual fault is a route nobody configured. The voorstel is parked in
parafering with no way forward and no way back.

This change makes `activate()` refuse instead. A voorstel that cannot be routed
is not put into parafering.

## Risks

- 🔴 **Activation must not fail closed on decidiq's absence.** An install without
  it keeps its local routes and keeps working, so the dispatch is additional
  rather than required. The honest cost is a partial state, which is why whether
  a voorstel was mirrored is RECORDED ON THE VOORSTEL and not only logged — a
  best-effort step whose only trace is a log line is one nobody can audit.
- 🔴 **A route resolved from the wrong place is a wrong signature chain**, and it
  would look entirely plausible. The directory matches on the SAME criteria the
  inline lookup used — the voorstel's caseType and `isDefault` — and nothing is
  ever matched by name.
- ⚠️ **Steps are copied into the snapshot, so an edited template does not rewrite
  a sign-off in flight.** True before this change and after it; asserted rather
  than assumed, because it is now true across an app boundary.
