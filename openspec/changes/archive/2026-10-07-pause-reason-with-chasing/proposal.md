---
kind: code
depends_on: [termijnbewaking-op-engine-timers]
---

# Proposal: pause-reason-with-chasing

Competitor gap register, row 2.25 "Automatic chasing while a case waits on
someone else" (`procest/_gaps/gap-register.md` in
ConductionNL/market-intelligence, 2026-09-13). Rated partial, owner dossiq,
size M.

## Why

When a case waits on the applicant, dossiq records why and then waits too.
`DeadlinePauseService::registerPauze()` takes a free-text `rationale` and a
`documentLink`, suspends the engine timer (basis Awb 4:5) and arms one
helper timer that fires once, when the hersteltermijn runs out
(`termijnbewaking-op-engine-timers` D-1, D-2). Nobody reminds the applicant
on day 7, nobody again on day 12, and the handler learns of the silence on
the last day.

The best competitor in the register: GLPI 11, `src/PendingReason.php:74-98`
with `PendingReasonCron.php:72`, a reason carrying its own chase interval,
text and budget (`_round3/compare/proposed-rows.md`).

## What changes

- A `pauseReason` schema in `register.d/60-termijnbewaking.json`, per
  case type: name, legal basis (Awb 4:5 or 4:15), chase interval in days,
  chase text, chase budget (how many), and whether the interval counts
  working days.
- The pause dialog picks a reason; `rationale` stays for a note.
- The helper timer carries one escalation rung per chase inside the budget.
  Each fire sends the chase text to the requester through the case's
  messaging leaf and records a `deadlineEvent` `chased`. After the budget,
  the last rung notifies the handler instead.
- Resuming cancels the remaining chases.

## Ownership

dossiq builds the schema, the dialog change and the fire handling: what a
pause reason is under Awb 4:5 and 4:15 is termijn configuration. It
consumes OpenRegister's flow-business-timers escalation rules (shipped) for
the clock, and the messaging leaf for the outgoing text (openregister
`messaging` leaf, shipped; the Berichtenbox channel through integriq's
`berichtenbox-integration` when the requester has one).

## ADRs

- Company ADR-022 and ADR-031: the clock is the engine's; the reason is
  declared data.
- Company ADR-075: the chase text leaves through the one document and
  message channel, never a dossiq mailer.

## Capabilities

- Modified: `termijn-pause-extension`: a pause has a reason with chasing.

## Impact

`lib/Settings/register.d/60-termijnbewaking.json` (`pauseReason`,
`deadlineInstance.pauseReason`); `lib/Service/DeadlinePauseService.php`;
`lib/Service/TermijnTimerService.php` (helper timer rungs);
`lib/Listener/TermijnTimerFiredListener.php` (`chased` rung); the pause
dialog; unit fixture pairs; one e2e spec.
