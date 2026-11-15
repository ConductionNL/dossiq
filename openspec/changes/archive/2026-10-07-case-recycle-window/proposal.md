---
kind: code
depends_on: []
---

# Proposal: case-recycle-window

Round 4 discovery, cluster 39 "Delete, restore and destroy"
(`procest/_round4/discovery/build-plan.md` in
ConductionNL/market-intelligence, 2026-09-14). Five candidates, seven
passers, five driven, proving system Vikunja. Owner openregister, size M,
wave 1. Decision D10. This change is dossiq's half.

## Why

`#Cases` deletes a case immediately. dossiq returns nothing for a trash, a
prullenbak, a soft delete or a restore window, and `CaseLifecycleController`
has no trash. Ledger row 4.15 covers documents only; nothing asks it of a
case.

C-case-core-11's clause: "an accidentally deleted bezwaar is an
Archiefwet problem".

## The candidates

| candidate | relevance | dossiq | what it asks |
|---|---|---|---|
| C-case-core-11 | must, matrix hole | no | a deleted case is recoverable for a stated period and destroyed by a second act |
| C-access-and-privacy-65 | must | partial | personal data is deleted when its lawful purpose ends, apart from the archive retention rule |
| C-access-and-privacy-50 | must | no | destroying a case also destroys the process data behind it |
| C-documents-20 | must | no | deleting a document is allowed only to the record manager role |
| C-access-and-privacy-14 | could | no | a person deletes everything they own in one act |

The proving passer, verbatim from the lane
(`_round4/discovery/candidates.json`, C-case-core-11, `case-core.tsv:8`):
"vikunja: Settings, Delete your Vikunja account, and the 30-day purge of
soft-deleted tasks (menu-tree.md, code-census.md)". Three driven passers:
Freescout, Plane and Vikunja. The lane's note: "Three systems, one
two-step delete. matrix hole".

Two of the `must` candidates carry documented passers only,
C-access-and-privacy-65 and C-access-and-privacy-50. **D6 was answered
relevance-led**, so both enter the corpus whatever their passer count, and
both are obligations rather than comparisons: the AVG and the Archiefwet.
**D17 was answered for a broad market**, and none of the twenty `not`
candidates is in this cluster.

## The decision this rests on

D10, answered as recommended: **option 3, both.** Verbatim: "a guard and a
recovery window solve different problems: one stops a mistake, the other
undoes it. Keep them separate in the specs: deletion on loss of lawful
purpose is not archive retention, and two clusters already say so."

The recycle state is openregister's, beside `object-archive-state`. The
guard stays dossiq's, and it is the open change `case-delete-guard`.

## What dossiq does

- `case-delete-guard` runs first and refuses a delete that would strand
  something. Only a permitted delete reaches the recycle state.
- A deleted case reads as deleted rather than disappearing, and the case
  list says how long it can still be recovered.
- Restoring is an act with a record: who restored it and when.
- Destruction is a second, separate act, and it names who decided.
- The two clocks are kept apart on the case, because the AVG says delete
  when the purpose ends and the Archiefwet says keep for N years. A
  product that treats them as one rule is wrong in both directions, which
  is C-access-and-privacy-65's own clause.
- Which role may destroy is declared per case type.

## Ownership

openregister owns the recycle state, the window, the purge job and the
destruction record. Its change is **to be specified in openregister, wave
1**, beside `object-archive-state` and the shipped `retention-management`
spec; this proposal names the slug once that lane opens it. dossiq owns
the guard, the declaration of who may destroy, and the two clocks read
apart on the case. dossiq builds no soft delete.

## Capabilities

- Modified: `case-management`: a deleted case can be got back, and
  destroying one is a second decision.

## Impact

`lib/Controller/CaseLifecycleController.php`, the `caseType` schema (the
destroying role and the window), the case list's deleted lens,
`src/manifest.json`, Dutch and English strings.

## Out of scope

- The soft delete, the window and the purge. openregister, wave 1.
- Archival nomination and the vernietigingslijst. Cluster 43, filinq, D7.
- The data subject's own export and erasure request. Cluster 38,
  openregister, D10 and D22.
- Deleting a document, C-documents-20's own half. filinq owns the
  document; dossiq declares the role.
