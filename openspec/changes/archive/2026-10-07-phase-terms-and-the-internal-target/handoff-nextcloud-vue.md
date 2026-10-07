# Handoff to nextcloud-vue: a progress and days-left column

Task 8.2 of `phase-terms-and-the-internal-target`. Candidate id
**C-deadlines-4**, round 4 discovery cluster 18, evidence
`deadlines.tsv:28`, "xxllnc-zaken: Case data model (case-management/spec.md)".
The clause: a progress percentage computed from the phases and the term,
with a days-left count, in every list.

## What is asked for

One index-column type that renders a progress figure and a days-left count
for a row, from a value the app supplies. `index-columns-per-scope` is the
change the parity register names for row 11.9 and it covers WHICH columns a
list shows. It does not carry a column type that draws a figure beside a
count, which is the gap.

## The one requirement

🔴 **The column renders the number the app answered and computes none of
it.** dossiq answers `progress` and `daysLeft` from
`GET /apps/dossiq/api/cases/{caseId}/terms`, derived at read time from the
bound terms and stored nowhere. A column that recomputed either from the
row would be a second calculator of the same question, and the second one
differs from the first the moment a term is paused over a weekend or moved
onto the organisation's working calendar. The case page and the list would
then disagree about a case, on screen, with no way for a handler to tell
which is right.

So the column takes the figure as a value and draws it. It does not take
phases and a term and divide them.

## The shape dossiq answers

```json
{
  "case": "…uuid…",
  "terms": [
    {
      "id": "…", "kind": "statutory",
      "startDate": "2026-09-01T00:00:00+00:00",
      "endDate": "2026-10-27",
      "status": "lopend", "statusType": "", "plannedStartDate": "",
      "daysLeft": 42, "overdue": false, "citizenVisible": true
    }
  ],
  "progress": {
    "progress": 45, "daysLeft": 42,
    "phasesDone": 1, "phasesTotal": 4, "termConsumed": 25,
    "phaseOverdue": true, "plannedOverdue": false, "statutoryOverdue": false
  }
}
```

`progress.progress` is 0 to 100. `progress.daysLeft` counts against the
statutory term and goes negative once it has passed. The three `*Overdue`
booleans are separate on purpose: a case late against its plan and on time
against the Awb reads as both, and folding them into one flag is the defect
this change exists to end.

## Until it lands

dossiq computes the value and the case page shows it, in the Terms sidebar
tab on `#CaseDetail`. Only the list column waits. dossiq deliberately does
not ship a column that computes in the browser in the meantime, because a
column that disagrees with the case page is worse than a column that is not
there yet.
