---
kind: config
depends_on: []
---

# Proposal: planned-case-series

Competitor gap register, row 1.8 "Scheduled or recurring planned cases"
(`procest/_gaps/gap-register.md` in ConductionNL/market-intelligence,
2026-09-13). Rated partial, owner dossiq, size S. Re-read on 2026-09-13
(`docs/research/competitor-gap-re-read-2026-09-13.md`): the gap stays.

## Why

You can plan one follow-up case from a case, and only one. The header
action `plan-follow-up` on `#CaseDetail` writes a scheduled flow through
`lib/Service/Flow/PlannedFollowUpDocument.php`, and its header comment says
what it cannot do: "the cron pins the planned date rather than a recurrence",
and `PlannedFollowUpSweepJob` switches the flow off once it has fired. An
annual inspection, a quarterly review or a yearly permit check is a series,
and a series does not exist.

The best competitor in the register: xxllnc Zaken,
`frontend-mono/apps/main/src/modules/case/views/relations/Relations.locale.ts`
(`_round2/compare/M1-functionality.md`).

## What changes

- The plan-follow-up form gains a recurrence: none (today's behaviour),
  monthly, quarterly, half-yearly, yearly, plus an end date or a count.
- A series is one scheduled flow whose cron fields express the recurrence.
  The sweep job stops switching it off when a recurrence is set, and switches
  it off when the end date or the count is reached.
- The Related tab of `#CaseDetail` shows the series with its next occurrence
  and lets you stop it.
- Every occurrence names the series in `handoffSource`, so the planned cases
  of one series are one query.

## Ownership

dossiq builds the recurrence rule, the sweep behaviour and the Related tab
rows. dossiq consumes the scheduled trigger of OpenRegister's flow engine as
it does today (`x-openregister-flows`, ADR-031; `flow-business-timers` on
openregister `development`). Nothing new is asked of openregister.

## ADRs

- Company ADR-031 (schema-declarative business logic): the schedule is a
  declared flow trigger, not a dossiq cron.
- Company ADR-069 (background job conventions): one sweep job, registered
  once, `TimedJob`.
- Company ADR-099 (acting on behalf of a user): the trigger carries `runAs`
  and keeps it for every occurrence.

## Capabilities

- Modified: `workflow-definition-engine`: a planned follow-up can repeat.

## Impact

- `lib/Service/Flow/PlannedFollowUpDocument.php`: recurrence to cron fields,
  end condition in the document.
- `lib/BackgroundJob/PlannedFollowUpSweepJob.php`: stop only when the series
  is spent.
- `src/manifest.json` `#CaseDetail`: the `plan-follow-up` action's form and
  the Related tab's planned-cases panel.
- `tests/vitest/caseActionsMenu.spec.js`, a unit pair for the document, one
  e2e spec.
