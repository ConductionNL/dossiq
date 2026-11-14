---
kind: config
depends_on: [email-case-matching]
---

# Proposal: case-merge

Competitor gap register, row 2.23 "Merge two cases into one"
(`procest/_gaps/gap-register.md` in ConductionNL/market-intelligence,
2026-09-13). Rated no, owner openregister, slug `case-merge (dossiq,
consumes mdm-merge)`, size M. This is dossiq's half; the merge itself is
OpenRegister's MDM merge.

## Why

Two applicants file the same request, or one files twice, and dossiq keeps
both cases open for ever. The nearest thing is
`lib/Controller/CaseRelationController.php`, which relates cases without
collapsing them. A duplicate case carries its own term, its own tasks and
its own documents, and a reply to the duplicate's number lands on the
wrong case.

The best competitor in the register: osTicket 1.18, three declared merge
modes and an unlink, `include/class.ticket.php:121-124`
(`_round4/compare/promoted-rows-batch3.md`).

## What changes

- The `case` schema declares its merge rule in the `mdm-merge`
  vocabulary: which parts relink to the survivor (tasks, documents,
  roles, relations, notes, mail), which fields the survivor keeps, and the
  reversal window.
- Header action Merge into on `#CaseDetail`: pick the survivor, confirm,
  and the platform merges. The merged case ends with result `merged`,
  `mergedInto` set, and its open term completed with the reason merged;
  the survivor's term is the one that counts.
- A reply or a portal message addressed to the merged case's number
  reaches the survivor: `email-case-matching` and `#PublicStatus` follow
  `mergedInto`.
- Unmerge inside the window is the platform's reversal; dossiq reopens the
  case and re-arms its term from the completed instance.

## Ownership

dossiq builds the declaration, the action and the two followers. It
consumes openregister `mdm-merge` (spec exists on openregister
`development`) for the merge and the reversal, and `email-case-matching`
(dossiq, open) for the reply routing.

## ADRs

- Company ADR-045: OpenRegister owns the MDM surface, merge included.
- Company ADR-031: the rule is declared on the schema.
- Company ADR-078: the post-merge work (term completion) is a deferred
  listener on the platform's merge event.

## Capabilities

- Modified: `case-management`: two cases become one, and the old number
  still finds it.

## Impact

`lib/Settings/dossiq_register.json` (`case` merge rule, `mergedInto`,
result `merged`); `src/manifest.json` `#CaseDetail` action; a listener on
the merge event for the term; `email-case-matching` and `PublicStatusPage`
resolve `mergedInto`; unit tests; one e2e spec.
