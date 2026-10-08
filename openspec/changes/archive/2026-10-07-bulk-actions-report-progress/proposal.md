---
kind: code
depends_on: []
---

# Proposal: bulk-actions-report-progress

Round 4 discovery, cluster 52 "Bulk action as a background job"
(`procest/_round4/discovery/build-plan.md` in
ConductionNL/market-intelligence, 2026-09-14). Eight candidates, three
passers, all three driven, proving system Dimpact ZAC. Owner openregister,
size L, wave 1. This change is dossiq's half.

## Why

C-case-core-1's clause: "'it skipped 12 of 400 and here they are' is the
difference between a bulk action and a leap of faith". dossiq has a bulk
status transition and a bulk reassignment, both in the browser, neither
reporting what it skipped.

C-case-core-2's is sharper: "a bulk action over four hundred statutory
cases is unrecoverable and nothing in the corpus offers a dry run".

## The candidates

| candidate | relevance | dossiq | what it asks |
|---|---|---|---|
| C-case-core-1 | must, matrix hole | no | a bulk action runs as a background job and reports its progress, naming what it skipped |
| C-case-core-2 | must | no | a bulk action runs in simulation first, and what it would have done is read before committing |
| C-case-core-4 | must | no | a bulk distribution needs a written justification |
| C-case-core-45 | must | partial | the work of a handler who left or is on leave is released in bulk |
| C-reporting-30 | must | no | your background jobs listed, cancellable while running, with the result or the error report downloadable |
| C-case-core-3 | should | no | a bulk attribute change is refused unless the selection is one case type version |
| C-search-17 | should | no | every row matching the search is selected, not only the page, and the product says which of the two you have |
| C-configuration-20 | could | no | a long running administrative operation reports its progress |

The proving passer, verbatim from the lane
(`_round4/discovery/candidates.json`, C-case-core-1, `case-core.tsv:13`):
"dimpact-zac: Bulk operations (websocket-events/spec.md)". Two driven
passers, Dimpact ZAC and xxllnc Zaken, and the lane's note: "Both take the
bulk action off the browser and make it report. matrix hole".

Four of the five `must` candidates have one driven passer each. **D6 was
answered relevance-led**, so every one of them enters the corpus whatever
its passer count, and none is deferred for want of a second column.
**D17 was answered for a broad market**, and none of the twenty `not`
candidates is in this cluster.

## The decision this rests on

None. `build-plan.md` names no decision for cluster 52. Wave 1 puts the
job itself on openregister.

## What dossiq does

- Hands a bulk act to openregister's job instead of looping in the
  browser, for the two acts it already ships and for any it adds.
- Renders the progress, the per-row outcome and the skip list, so a
  handler reads what happened to which case.
- Requires a written justification on a bulk distribution, because
  reassigning four hundred cases with no recorded reason is an audit
  finding waiting to happen. That is a case policy, so it is dossiq's.
- Refuses a bulk attribute change across two versions of a case type,
  which is the guard that makes a dry run worth having.
- Releases a departed or absent handler's caseload in bulk, through the
  same job, extending `lib/Controller/SubstitutionController.php`.
- Says, when a handler selects everything, whether they have the page or
  the whole result.

## Ownership

openregister owns the job, its progress, its cancellation, the simulation
and the per-row outcome record. Its change shipped as **`bulk-action-jobs`**
(openregister#3742, `f88d986b5`), which is the slug `build-plan.md`
proposed. dossiq renders, declares the guards and holds the justification.
dossiq ships no job runner.

## Capabilities

- Modified: `case-management`: a bulk act is a job that reports, not a
  loop that hopes.

## Impact

`lib/Service/` for the two shipped bulk acts,
`lib/Controller/SubstitutionController.php`, `src/manifest.json` (the
progress surface and the select-all affordance), Dutch and English
strings.

## Out of scope

- The job, its progress, its cancellation and the simulation.
  openregister, wave 1.
- The administrator's job monitor with logs and a run-now button. Cluster
  1, openregister, and it is the loudest gap in the whole sweep.
- Export as its own right. Cluster 16, openregister.
