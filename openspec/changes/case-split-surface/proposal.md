---
kind: code
depends_on: []
---

# Proposal: case-split-surface

The half `splitting-a-case-and-its-incidents` named as missing when it
shipped. Its own tasks.md says it, under "What is not built, and named rather
than left to be discovered":

> **The split picker dialog.** The header action is deliberately NOT declared
> in `src/manifest.json`. An `open-modal` action naming a target the component
> registry does not answer renders nothing at all, with no warning and no
> console error, so declaring it now would ship a button that does nothing and
> look exactly like a button that works. The server-side half is complete and
> tested: the bound, the refusal, the plan, the references and the relation.

## Why

`CaseSplitPolicy` and `CaseSplitPlan` are merged, tested and reachable from
nothing. `grep` finds no caller of either, and `appinfo/routes.php` carries no
split route, so a handler looking at a case that ought to be two cases has
exactly what they had before: a copy that duplicates, and their own hands.

Rules with no surface are not a smaller version of the feature. They are the
part nobody can use, and the longer they sit the more likely the next reader
takes them for dead code and deletes them.

## What changes

- `CaseSplitPerformer`: the store-facing half. It reads the case, reads the
  chosen rows, opens the second case, performs the plan's moves and writes the
  relation with the plan's note. Every judgement stays in the two services it
  calls; this one decides nothing.
- `GET /api/case/{caseId}/split` answers what this case holds, per part the
  case type allows.
- `POST /api/case/{caseId}/split` performs it, and passes the policy's refusal
  sentence through verbatim.
- `CaseSplitDialog` and a Split in two header action beside Merge into.

## Ownership

dossiq, throughout. The rules and the surface are the same change split over
two PRs because a parallel lane built the rules first.

## Capabilities

- Modified: `case-management`: a handler can divide a case from its own page.

## Impact

`lib/Service/Cases/CaseSplitPerformer.php` (new);
`lib/Controller/CaseSplitController.php` (new); `appinfo/routes.php`;
`src/dialogs/CaseSplitDialog.vue` (new); `src/manifest.json`;
`src/registry.js`; `src/icons.js`; unit tests; one e2e spec.
