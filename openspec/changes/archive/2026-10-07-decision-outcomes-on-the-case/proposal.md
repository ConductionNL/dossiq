---
kind: code
depends_on: []
---

# Proposal: decision-outcomes-on-the-case

The dossiq consumer half of **decidiq `the-decision-as-a-walked-process`**
(ConductionNL/decidiq#1316), round 4 discovery cluster 22 "The decision as
a walked process" (`procest/_round4/discovery/build-plan.md` in
ConductionNL/market-intelligence, 2026-09-14). Eleven candidates, six of
them `must`, owner decidiq, size L. dossiq owns none of the walk and
consumes three things from it: the approval outcome, the admissibility
verdict and the remedy clause.

## Why

dossiq has already moved decision-making out: `dossiq-decisions-to-decidiq`
and `migrate-committees-to-decidiq` are open, and `besluitvorming-leaf`
places decidiq's surface on the case. What is missing is the other
direction. A decision walked in decidiq changes what the case may do, and
today the case does not read it.

Three consequences, each on a case:

- A case cannot proceed while named approvers have not signed off.
- An inadmissible verdict at intake ends the case there, rather than
  leaving a case in a phase nobody will work.
- The remedy open against a decision is declared on the case type and
  printed on the decision, so a besluit carries its bezwaarclausule.

## The candidates dossiq consumes

| candidate | relevance | dossiq | what dossiq's half is |
|---|---|---|---|
| C-decisions-1 | must | no | the case does not proceed while a named approval is outstanding |
| C-decisions-13 | must | partial | an inadmissible verdict at intake ends the case, with the result recorded |
| C-decisions-25 | must | partial | the remedy clause is declared on the case type and printed on the decision |

Evidence verbatim from the lane
(`_round4/discovery/candidates.json`):

- C-decisions-1: "request-tracker: Tools, Approval
  (share/html/Approvals/), approvals lifecycle".
- C-decisions-13: "dimpact-zac: Intake phase
  (browser-walkthrough-notes.md)".
- C-decisions-25: "xxllnc-zaken: Case type > Documentatie
  (case-type-editor-anatomy.md)".

The other eight members are decidiq's and dossiq builds no half of them
here: C-decisions-2 (a withdrawn decision), C-decisions-3 (a future
effective date), C-decisions-4 (a threshold of approvers), C-decisions-7
(a risk score), C-decisions-10 (an approval withdrawn when what was
approved changes), C-decisions-16 (the applicant naming approvers),
C-decisions-17 (the committee agenda item) and C-decisions-22 (refusing a
decision the case type does not allow, which dossiq already passes).

**D6 was answered relevance-led**, so all three `must` candidates enter.
**D17** does not reach this cluster.

## What changes

- A case type declares which decisions require a walked approval before
  the case may move on. While one is outstanding, the acts that depend on
  it are refused with the rule named, and the case shows what it is
  waiting for and on whom.
- The approval outcome is read from decidiq and is not recomputed in
  dossiq. An outcome dossiq cannot read leaves the case blocked and says
  so, rather than letting it pass.
- A case type declares that intake ends with an admissibility judgement.
  An inadmissible verdict closes the case with that result, records who
  judged it, and tells the applicant through the declared moments.
- A case type declares the remedy open against its decisions: the kind,
  the term in days and the body it is lodged with. The decision document
  prints it, and the term is bound as a term instance when the decision is
  sent.

## Ownership

decidiq owns the walk, the approvers, the thresholds, the risk score, the
withdrawal and the committee. dossiq owns the case type declaration, the
gate on the case, the close at intake and the printed clause.

| half | app | artefact |
|---|---|---|
| the approval as a walked process, and its outcome | decidiq | `the-decision-as-a-walked-process`, decidiq#1316 |
| the decision record the clause is printed on | dossiq | `beschikking-generatie` and `frozen-beschikking-and-numbered-successor`, open |
| the term the remedy clause starts | dossiq | `phase-terms-and-the-internal-target`, this wave, and `termijn-binding` |
| the message telling the applicant of an inadmissible verdict | dossiq | `ontvangstbevestiging`'s declared moments, this wave |

Every half has an artefact. This change opens no request for a new change
in another repo.

## ADRs

- Company ADR-050: the error envelope is `{message, error}`. An act
  refused for an outstanding approval names the approval in `error`.
- Company ADR-102: config absence fails closed with a status. An approval
  outcome dossiq cannot read leaves the case blocked.
- Company ADR-011: search OpenRegister before implementing a utility.
  dossiq stores no approval, no approver and no threshold.

## Capabilities

- Modified: `besluitvorming-leaf`: the case reads the approval outcome and
  is gated by it.
- Modified: `beschikking-generatie`: a decision prints the remedy clause
  its case type declares, and the remedy term is bound when it is sent.

## Impact

`caseType` (the approval requirement, the admissibility declaration and
the remedy clause), `lib/Lifecycle/CaseActionProvider.php`, the decision
document template, Dutch and English strings.

## Out of scope

- Everything decidiq owns, listed above.
- The bezwaar procedure itself. dossiq `bezwaar-beroep-workflow`, shipped.
