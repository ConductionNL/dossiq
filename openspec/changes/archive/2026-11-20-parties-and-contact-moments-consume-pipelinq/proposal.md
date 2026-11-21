---
kind: feature
depends_on: []
---

# Proposal: parties-and-contact-moments-consume-pipelinq

The consumer half of seven pipelinq changes that landed together on 2026-09-18:
contact moments on pipelinq's schema (pipelinq#1970), one contact moment on
several cases (#1972), typed fields and standing indicators on a party (#1973),
party kinds accepted per case type (#1975), the correspondence language per
party (#1976), the customer satisfaction closed loop (#1977) and the programme
above the cases (#1978).

pipelinq shipped the logic. This is the app that declares and renders it.

## Why

Three things are true at once, and they pull in different directions.

**dossiq already has a parties surface.** `parties-on-the-case` (#2849) shipped
`PartyVocabulary`, `PartyIndicatorReader`, `CaseRoleVocabulary`,
`src/services/caseParties.js` and the `case-party-roles` widget. None of that is
thrown away here. What changes is where two of its answers come from: the list
of party kinds, and whether an act on a party is refused.

**dossiq already has two contact moment models.** `contactmoment`
(`register.d/40-kcc-werkplek.json`) and `customerContact`
(`register.d/30-kcc.json`, on top of the monolith's declaration), read and
written by `Service\ContactMomentService` and `Service\Kcc\ContactMomentService`.
A schema slug is global per organisation, and pipelinq declares the fleet's
`contactmoment` as a facet of its `ticket` supertype. Two declarations under one
slug is the collision the 2026-09-05 fleet audit found eighteen times.

**dossiq must keep working without pipelinq.** pipelinq is not a dependency of
dossiq and must not become one. Every read here degrades: when pipelinq is
absent, dossiq answers from what it already has and says so, rather than
rendering an empty panel that looks exactly like a case with nothing on it.

## What this change replaces, and what it feeds

| dossiq surface, today | after this change |
|---|---|
| `PartyVocabulary::kinds()`, three hardcoded kinds | **fed** by pipelinq's registry when present; its own three are the fallback for an instance without pipelinq |
| `PartyVocabulary::roles()`, six hardcoded roles | unchanged as the shipped default, and **declared** to pipelinq as the acceptance for each case type |
| `PartyIndicatorReader`, against OpenRegister's `PartyIndicatorGuard` | **kept and joined**: pipelinq's indicators are read beside it, and an act is refused when EITHER refuses |
| `Service\ContactMomentService` (`contactmoment`) | **fed**: it keeps its KCC workflow, and the moment it writes is appended through pipelinq's leaf when pipelinq is present |
| `Service\Kcc\ContactMomentService` (`customerContact`) | **unchanged here**, and named for the follow-up that retires it |
| nothing | **new**: the correspondence language resolver, the satisfaction dispatch, the programme link |

The two dossiq schemas are NOT deleted by this change. Deleting a schema that
holds rows is a migration, and a migration belongs to a change whose whole
subject it is. What happens here is that the write path stops being the only
one: a contact moment logged on a case is appended to pipelinq's record as well,
so the follow-up that retires the dossiq copies has something to migrate onto.

## What dossiq declares

- **The case's leaves.** `case.configuration.linkedTypes` gains
  `pipelinq-contact-moments` and `pipelinq-party`, so the two panels render on a
  case without dossiq querying pipelinq's register.
- **Party kind acceptance per case type.** For each case type, an ordered set of
  kinds, written to pipelinq as `<app>:<schema>:<type>` =
  `dossiq:case:<caseType>`. The order is the order handlers see.
- **Nothing else.** No party field, no indicator, no party kind list of its own
  once pipelinq answers, no programme object, no budget field on a case, and no
  second contact moment schema.

## What dossiq asks

- Before publishing a case, and before sending anything to a party: pipelinq's
  blocking answer, naming the indicator. A caller that does not ask is the
  defect the contract exists to make visible.
- Which language to write to a party in, through the resolver, never by reading
  `correspondenceLanguage` off a record.
- How far along a programme is, with the mode that produced the figure.

## The shape of the seam

Every call is a **duck-typed resolve of a pipelinq class through the server
container**, guarded by `class_exists` and `method_exists`, the way
`PartyIndicatorReader` reaches OpenRegister's guard and
`CommitteeDelegationService` reaches decidiq's event. Not REST: ADR-041 and
gate-27 say an in-fleet command travels as a typed call, and the REST seam is
the door for external callers.

pipelinq absent means every reader answers its own fallback and every writer is
a no-op that logs once. That is a deliberate fail-open, and it is stated in each
requirement rather than left to be discovered.

## What this change does not do

- It does not delete `contactmoment` or `customerContact`. Named as the
  follow-up, with the bridge this change builds as its migration path.
- It does not move the KCC werkplek surface. The quick actions, the belplan, the
  doorverbinding and the sentiment stay exactly where they are.
- It does not write a party. pipelinq owns the party record; dossiq links to one.
