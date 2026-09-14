---
kind: code
depends_on: []
---

# Proposal: status-capacity-limit

Competitor gap register, row Q3.22 "Can a stage hold a limit, and does the
product refuse work past it" (`procest/_gaps/gap-register.md` in
ConductionNL/market-intelligence, 2026-09-13). Rated no, owner dossiq,
slug `status-capacity-limit`, size S. Last sweep of the OpenSpec phase.

## Why

A handling desk that takes on more work than it can finish is how a term
gets missed, and nothing in dossiq notices. A status carries an order, a
colour and a checklist; it carries no number.

The register's note: "From a grep: `wip|task_limit|column.?limit|maxCards|bucketLimit`
over `lib/` returns 4 hits, every one a query limit in
`StatusChecklist.php`; no stage carries a capacity. Vikunja refused the
N+1th card with 412 code 10004 and a sentence, measured; Kanboard coloured
the column header and let two cards into a column with a limit of 1,
measured."

The best competitor, verbatim from the register's `best` column: "Vikunja
2.6.0: buckets.limit (pkg/models/kanban.go:44) and checkBucketLimit; task
2 into a full bucket refused with 412 code 10004, measured
(`_round4/compare/proposed-rows-batch7.md`)". The measured difference
between the two competitors is the whole row: Kanboard colours a header
and lets the work through, Vikunja refuses it. A limit that does not
refuse is decoration.

## What changes

- `statusType.capacity`: a whole number of cases the status may hold at
  once. Absent or zero means no limit, which is every status today.
- A `statusCapacity` guard in `GuardRegistry`, evaluated on every
  transition into the status, template or not, the way the checklist guard
  already is.
- The refusal names the status, the limit and the count that was found, so
  the handler reads a sentence rather than a code.
- Moving a case out of a full status is always allowed. A full status that
  cannot be emptied is a deadlock.
- The board column and the status chip show the count against the limit,
  and a drag into a full column is refused before the card lands.
- A bulk transition refuses only the cases past the limit and reports
  which, rather than refusing the whole selection.

## Ownership

dossiq builds all of it. A capacity on a stage is `statusType`
configuration and the refusal is a transition guard, both dossiq's, as the
register's `why` says: "a capacity on a stage is statusType configuration
and the refusal is a transition guard, both dossiq's". Nothing is
consumed from another app.

## ADRs

- Company ADR-031: the limit is declared on the status type, not coded in
  a controller.
- Company ADR-023: the refusal is an authorization-shaped answer on the
  action, not a silent no-op in the UI.
- Company ADR-105: the refusal reaches the caller as a status and a
  message, which is the rule `refusals-carry-a-status` is built on.

## Capabilities

- Modified: `status-transition-engine`: a status may hold a limit, and the
  transition into it is refused past it.

## Impact

`lib/Settings/dossiq_register.json` (`statusType.capacity`),
`lib/Service/Transitions/GuardRegistry.php` and a new
`CapacityGuard`, `src/manifest.json` (the board column and the status
chip), `tests/vitest/`, `tests/Unit/Service/Transitions/`, one e2e spec.

## Out of scope

- A limit per handler or per team. The row asks about a stage; a personal
  work-in-progress limit is a different question with a different owner.
- A soft limit that warns and lets the work through. That is Kanboard's
  answer and the register measured it as the losing one.
