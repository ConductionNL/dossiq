# Design: pause-reason-with-chasing

## D-1. The reason is data on the definition side

`pauseReason` {caseType, name, legalBasis, chaseIntervalDays, chaseText,
chaseBudget, countsWorkingDays}. `deadlineInstance.pauseReason` references
the one in force. Free-text `rationale` stays: the reason is the rule, the
rationale is the note.

## D-2. Chases are rungs on the helper timer

`armHersteltermijn()` already arms a `due` helper with one explicit rule at
`slaBreached:0`. With a reason it adds `chaseBudget` rules at
`offset: -(pauseDays - k * chaseIntervalDays)` for k = 1..budget, each with
message `pauze-chase` and the chase number in metadata. The engine fires
them; dossiq keeps no schedule. Working-day counting uses the timer's unit
(`businessDays`) so the engine calendar answers it.

## D-3. The listener sends and records

On `pauze-chase`: if the instance is still `paused`, send `chaseText`
through the messaging leaf to the case's requester and write a
`deadlineEvent` `chased` (basis the reason's legal basis, actor system). On
the last chase inside the budget, notify the handler with "no reply after N
reminders". A fire after resume is ignored, as the existing `pauze-verlopen`
rung is.

## D-4. Resume cancels the rest

`resumeAfterPauze()` cancels the helper timer; the engine's single-timer
cancel gap named in termijnbewaking D-2 applies until upstream ships
`cancel(uuid)`, so the listener's `paused` check is the guard.
