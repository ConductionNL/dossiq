# Design: dwell-time-on-the-working-calendar

## D-1. Two numbers from one interval

`DwellTimeAnalyzer::hoursBetween(enteredAt, exitedAt)` becomes
`{workingHours, wallHours}`. Working hours come from the engine's
`SlaCalculator::elapsedBusinessHours()` (or the nearest method the engine
exposes; task 1.1 names it) against the organisation calendar. Wall hours
stay as today.

## D-2. Degradation

Engine absent: working hours are computed with `WorkingDayCalculator` at
8 hours per working day and the header says "working days × 8", the D-7
posture of termijnbewaking, until phase 3.

## D-3. Per assignee

Status records carry the actor; the analyser groups the same intervals by
actor as it groups by phase. No new data.
