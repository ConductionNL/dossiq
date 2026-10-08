# Design: terms-on-the-engine-calendar

## D-1. The roll is the engine's rule, switched on per term

`rollToWorkingDay` maps to the timer's calendar rule
(`end-date-roll-on-the-calendar` defines it). `createTermijnInstance()`
applies the same roll to `endDateCalculated` through the engine's
`SlaCalculator`; a fixture pair pins equality for a term ending on
Koningsdag and one ending on a Sunday.

## D-2. Legal confirmation gates the default

Awt art. 3 names the recognised holidays. Task 1.1 records who confirmed
the list against dossiq's case types and when; only then does the default
flip to true for `legalBasis` Algemene termijnenwet. Until then the flag
is false and visible in the termijn settings.

## D-3. No local calendar

`NoLocalCalendarTest` greps `lib/` for holiday literals and calendar
classes and fails any file not in a reason-bearing allowlist. The
allowlist holds `WorkingDayCalculator` with "termijnbewaking phase 3.2".

## D-4. The zone

Term dates are calendar dates, and a calendar date needs a zone to become
a day boundary. `TermijnService` builds every date in the organisation
calendar's zone (the engine answers it; `Europe/Amsterdam` for the seeded
`nl-national`). `tenantConfiguration.timezone` is not the source: a
tenant's display zone does not move a statutory day.

## D-5. History on the page

`case-terms` gains a Moved dates section reading the superseded-timer
history for the instance, one line per move with the reason.
