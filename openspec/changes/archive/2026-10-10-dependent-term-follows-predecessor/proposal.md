---
kind: code
depends_on: [termijnbewaking-op-engine-timers]
---

# Proposal: dependent-term-follows-predecessor

Competitor gap register, row Q3.21 "Does a link between cases move dates"
(`procest/_gaps/gap-register.md` in ConductionNL/market-intelligence,
2026-09-13). Rated no, owner dossiq, size M.

## Why

A vergunning that waits on a bezwaar has a link and no consequence.
`CaseRelationService` stores `vervolg`, `subject` and `bijdrage` in
`case.relatedCases` symmetrically (`RELATION_TYPES`, line 56) and
`CaseRelationController` relates cases without behaviour. When the bezwaar's
term is extended, the vergunning's handler finds out when their own term
breaches.

The best competitor in the register: OpenProject 16, follows and precedes
carry a lag, `app/models/relation.rb:104-105`, with_lag verified live
(`_round4/compare/proposed-rows.md`).

## What changes

- A relation type `waitsOn` beside the three ZGW types, with the inverse
  `blocks`, stored as today in `relatedCases`.
- When a `deadlineEvent` of kind `verleng` or `pauze` is recorded on a
  case, every case that `waitsOn` it gets an engine task for its handler:
  "The term of <case> moved by N days. Extend this term?" with one action
  that calls `DeadlineExtensionService` with the reason "follows <case>".
- Nothing extends automatically: Awb 4:14 requires a notice to the
  applicant, so a person decides.
- The Related tab shows `waitsOn` and `blocks` in both directions.

## Ownership

dossiq builds the type, the listener and the offer: a statutory term that
moves is dossiq's. It consumes OpenRegister's engine task (shipped) for the
offer and, once openregister's `relation-types-with-inverses` lands (row
2.26, to be specified in openregister), the type and its inverse move onto
the relation primitive and `relatedCases` stops carrying them.

## ADRs

- Company ADR-078: the reaction to a post-event is asynchronous, through
  `ListenerDeferralService`.
- Company ADR-031: the offer is a task, not a dossiq scheduler.
- Company ADR-058: the dependents query is bounded.

## Capabilities

- Modified: `related-case-linking`: a case can wait on another, and a
  moved term is offered to its dependents.

## Impact

`lib/Service/CaseRelationService.php`; new
`lib/Listener/DependentTermListener.php` on the `deadlineEvent` create;
`src/manifest.json` `#CaseDetail` Related panel; unit tests; one e2e spec.
