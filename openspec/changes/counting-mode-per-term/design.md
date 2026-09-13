# Design: counting-mode-per-term

## D-1. One property, two readers

`countingMode` enum `calendarDays|workingDays`, default `calendarDays`.
`armBeslistermijn()` maps it to `sla.unit` (`calendarDays` or
`businessDays`, the engine's word). `createTermijnInstance()` computes the
end date with the same mode.

## D-2. Working days come from the engine

For `workingDays`, `createTermijnInstance()` asks the engine's
`SlaCalculator` against the organisation calendar, not
`WorkingDayCalculator`. When the engine is absent it degrades to
`WorkingDayCalculator` with a warning, the D-7 posture of termijnbewaking,
until phase 3 retires it.

## D-3. The pair

Fixture: a 10-day term from a Thursday before a Dutch holiday, both modes;
the instance's `endDateCalculated` equals the engine's projected fire date
at day granularity in each mode.
