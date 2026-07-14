# process-mining-bottlenecks Specification

## Purpose
Lets a coordinator identify which case statuses are the real bottlenecks —
per-status dwell time, a bottleneck ranking, rework-loop detection, and a
weekly throughput trend — computed from the `statusRecord` history procest
already records on every case status transition, so bottleneck analysis no
longer requires exporting data to a separate process-mining tool.

## ADDED Requirements

### Requirement: Per-status dwell-time statistics
The system MUST compute, per case type and per status, the median, 90th
percentile, and mean dwell time in hours, derived from the gap between a
case entering a status (its `statusRecord` timestamp) and either the next
recorded transition, the case's `endDate` (closed case, final status), or
the computation time (open case, current status).

#### Scenario: A closed case's dwell interval ends at the next transition
- **GIVEN** a case with `statusRecord` rows entering `intake` at
  `2026-06-01T09:00:00Z` and `review` at `2026-06-02T09:00:00Z`, and
  `endDate: "2026-06-05T09:00:00Z"`
- **WHEN** `ProcessMiningService::computeDwellIntervals()` is called
- **THEN** the `intake` interval MUST be `24.0` hours (bounded by the next
  record, not the case's `endDate`)

#### Scenario: A closed case's final status dwell interval ends at endDate
- **GIVEN** the same case as above
- **WHEN** `computeDwellIntervals()` is called
- **THEN** the `review` interval (the case's last recorded status) MUST be
  `72.0` hours — bounded by `endDate`, not "now"

#### Scenario: An open case's current status dwell interval ends at "now"
- **GIVEN** a case with one `statusRecord` entering `intake` at
  `2026-06-01T09:00:00Z`, `endDate: null`, and computation time
  `2026-06-03T09:00:00Z`
- **WHEN** `computeDwellIntervals()` is called
- **THEN** the `intake` interval MUST be `48.0` hours

#### Scenario: Two records with an identical timestamp yield a zero-hour interval
- **GIVEN** a case whose `statusRecord` rows for `intake` and `review` share
  the exact same timestamp
- **WHEN** `computeDwellIntervals()` is called
- **THEN** the `intake` interval MUST be `0.0` hours, not omitted

#### Scenario: A case with no statusRecord history contributes nothing
- **GIVEN** a case with zero `statusRecord` rows
- **WHEN** `computeDwellIntervals()` is called
- **THEN** the case MUST contribute no dwell intervals
- **AND** the call MUST NOT raise an error

#### Scenario: Dwell intervals outside the requested period are excluded
- **GIVEN** a case with a `statusRecord` entering `intake` on `2026-05-01`
  (before the requested period) and `review` on `2026-06-15` (inside it)
- **WHEN** `computeDwellIntervals()` is called with period
  `[2026-06-01, 2026-06-30]`
- **THEN** only the `review` interval MUST be returned

#### Scenario: Aggregated stats report the nearest-rank median, p90, and mean
- **GIVEN** dwell intervals of `[10, 20, 30, 100]` hours for status `review`
- **WHEN** `aggregateDwellStats()` is called
- **THEN** `medianHours` MUST be `20.0` (the 2nd of 4 values, nearest-rank)
- **AND** `meanHours` MUST be `40.0`
- **AND** `visitCount` MUST be `4`

### Requirement: Bottleneck ranking
The system MUST rank statuses by a bottleneck score — median dwell time
multiplied by visit volume — highest first, so the status most worth
investigating surfaces at the top regardless of whether it's high-duration/
low-volume or low-duration/high-volume.

#### Scenario: A high-median, high-volume status outranks a low-volume one
- **GIVEN** dwell stats for `bottleneck` (median `50h`, `10` visits) and
  `low-impact` (median `5h`, `1` visit)
- **WHEN** `rankBottlenecks()` is called
- **THEN** `bottleneck` MUST rank first with `score: 500.0`
- **AND** `low-impact` MUST rank second

### Requirement: Transition matrix and rework detection
The system MUST build a from→to transition frequency matrix per case type,
and MUST flag a transition as rework when its target status was already
visited earlier in that case's own chronological history.

#### Scenario: A linear progression has no rework transitions
- **GIVEN** a case whose statuses, in order, are `A`, `B`, `C`
- **WHEN** `computeCaseTransitions()` is called
- **THEN** neither the `A→B` nor `B→C` transition MUST be flagged as rework

#### Scenario: Revisiting an earlier status is flagged as rework
- **GIVEN** a case whose statuses, in order, are `A`, `B`, `A`
- **WHEN** `computeCaseTransitions()` is called
- **THEN** the `A→B` transition MUST NOT be flagged as rework
- **AND** the `B→A` transition MUST be flagged as rework

#### Scenario: The matrix aggregates a transition-weighted overall rework percent
- **GIVEN** case 1 with statuses `A, B` and case 2 with statuses `A, B, A`
  (three transitions total, one of them rework)
- **WHEN** `computeTransitionMatrix()` is called
- **THEN** `totalCount` MUST be `3`
- **AND** `reworkPercent` MUST be `33.3`
- **AND** the `A→B` matrix row MUST report `count: 2, reworkCount: 0`
- **AND** the `B→A` matrix row MUST report `count: 1, reworkCount: 1`

### Requirement: Weekly throughput trend
The system MUST report the count of cases closed (by `endDate`) per ISO
week within the requested period, including weeks with zero closures.

#### Scenario: Cases closed in the same ISO week are summed into one bucket
- **GIVEN** three cases closed within period `[2026-06-01, 2026-06-14]` (two
  in the same ISO week, one in the next) and one open case (`endDate: null`)
- **WHEN** `computeThroughputTrend()` is called
- **THEN** the total count summed across all weekly buckets MUST be `3`
- **AND** the open case MUST NOT be counted

### Requirement: Per-case-type report grouping
The system MUST group cases by case type and compute dwell stats, bottleneck
ranking, transition matrix, and rework percent independently per case type;
an optional `caseType` filter MUST restrict the report to that one case type.

#### Scenario: The report is grouped per case type by default
- **GIVEN** cases of two different case types, each with recorded
  `statusRecord` history
- **WHEN** `ProcessMiningService::getReport([])` is called with no
  `caseType` filter
- **THEN** the response `caseTypes` array MUST contain one entry per case
  type present in the case population

#### Scenario: A caseType filter restricts the report to one case type
- **GIVEN** cases of case types `ct-1` and `ct-2`
- **WHEN** `getReport(['caseType' => 'ct-1'])` is called
- **THEN** the response `caseTypes` array MUST contain exactly one entry,
  for `ct-1`
- **AND** `caseTypeFilter` in the response MUST be `"ct-1"`

### Requirement: Report endpoint authorization
The process-mining report endpoint MUST be restricted to the
controllers/beheerders/admin roles, matching `Iv3ReportController`'s gate —
the report spans the whole tenant's case population, not the caller's own
work.

#### Scenario: An unauthenticated request is rejected
- **GIVEN** no active user session
- **WHEN** `GET /api/reports/process-mining` is called
- **THEN** the response MUST be `401 Unauthorized`

#### Scenario: A user outside the allowed groups is denied
- **GIVEN** an authenticated user who is not an NC admin and not in
  `controllers`/`beheerders`
- **WHEN** they call `GET /api/reports/process-mining`
- **THEN** the response MUST be `403 Forbidden` and MUST NOT include report
  data

#### Scenario: A controllers-group member receives the report
- **GIVEN** an authenticated user in the `controllers` group (not an NC
  admin)
- **WHEN** they call `GET /api/reports/process-mining`
- **THEN** the response MUST be `200 OK` with the report JSON body

#### Scenario: An invalid from/to date is rejected
- **GIVEN** an authenticated coordinator
- **WHEN** they call `GET /api/reports/process-mining?from=not-a-date`
- **THEN** the response MUST be `400 Bad Request`

#### Scenario: A service failure never leaks exception detail
- **GIVEN** an authenticated coordinator and a report-generation failure
- **WHEN** `GET /api/reports/process-mining` is called
- **THEN** the response MUST be `500` with a static message
- **AND** the response body MUST NOT contain the underlying exception's
  message text
