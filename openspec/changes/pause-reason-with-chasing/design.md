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

## D-5. Two triggers, one decision, and the count between them

The rungs above only exist when OpenRegister's timer engine does, and it is an
optional runtime dependency: `armHersteltermijn()` arms nothing without it. A
reminder that only goes out on instances with the engine installed is not a
reminder a gemeente can rely on, so `PauseChaseJob` sweeps the suspended terms
once a day and asks the same question.

That is a second trigger, not a second schedule. Both go through
`PauseChaseService::chaseIfDue()`, which re-reads the instance and asks
`ChaseSchedule` whether one is due against `chasesSent` and `lastChasedAt`, and
writes the new count in the same save as the send. A trigger arriving on a stale
row finds the count its predecessor wrote. A failed send counts nothing, so the
budget is never spent on a letter nobody received.

The sweep also catches what a rung cannot: a reason administered after the pause
was registered, and an interval an administrator changed this morning.

## D-6. The vocabulary is a list on the case type, not a schema

`pauseReason` is declared as `caseType.pauseReasons`, the way
`caseType.attentionMarkers` already declares its pairs, rather than as a schema
with rows pointing back at a case type. A schema would need a seeder, a
lifecycle and an admin surface of its own to carry what a ten-line declaration
carries, and the rows would be administered in a different place from every
other thing a case type declares about its terms.

What the instance keeps for itself is the key and the party, because those are
what the pause was REGISTERED as. Everything else is re-read from the case type
at fire time, so lengthening an interval reaches the pauses running now.

## D-7. What escalation means here

The escalation records a `chase-escalated` event on the term and an entry on the
case timeline naming the target the case type declared, and the queue then reads
"no reply after 2 reminders" beside the case. It does not push a Nextcloud
notification: ADR-031 owns the notification dialect, and a leaf app dispatching
its own is exactly what that ADR stops.
