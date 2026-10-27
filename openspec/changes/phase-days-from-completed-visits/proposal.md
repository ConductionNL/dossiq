# Proposal: phase-days-from-completed-visits

## Why

DqProcesanalyse "Dagen per fase" moved from day to day under the same example data: the dwell statistics count the visit
a still-open case is in up to now, so its days grow daily and every mean and median with it. A board drawn from one run
no longer matched the next (r33: Melding Jeugdwet 23 -> 14, Woo Ontvangst 9 -> 8).

## What changes

Each dwell interval says whether it is the open visit of a case still in progress. The report carries
`dwellTimeCompleted` (the statistics of visits that ended) beside `dwellTime`, and the days-per-phase matrix reads it.
The other blocks are unchanged.
