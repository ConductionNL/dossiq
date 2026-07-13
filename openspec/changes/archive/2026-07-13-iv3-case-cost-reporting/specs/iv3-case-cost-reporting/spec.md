# iv3-case-cost-reporting Specification

## Purpose
Lets a municipality classify case types by IV3/BBV taakveld, record
lightweight per-case cost entries, and generate the quarterly per-taakveld
cost report (case counts, recorded costs, leges income, average cost per
case) CBS's Informatie voor Derden (Iv3) submission requires — as JSON and
CSV — so a controller no longer hand-collates this from a separate financial
system.

## ADDED Requirements

### Requirement: IV3 taakveld reference list
The system MUST expose a versioned, single-source list of valid IV3/BBV
taakveld codes with labels, grouped by main category, that all other parts
of this feature (case-type classification, aggregation, CSV export, the
settings picker) validate and label against.

#### Scenario: Every taakveld code is well-formed and unique
- **GIVEN** the shipped `iv3_taakvelden.json` taakveld list
- **WHEN** `Iv3TaakveldList::allTaakvelden()` is called
- **THEN** every returned entry MUST have a non-empty `code` matching
  `/^\d+\.\d{1,3}$/`, a non-empty `label`, and a `categoryCode` in `0`..`8`
- **AND** no `code` value MUST appear more than once

#### Scenario: Known codes resolve to their documented labels
- **GIVEN** the shipped taakveld list
- **WHEN** `Iv3TaakveldList::labelFor('8.1')` and `labelFor('7.4')` are called
- **THEN** they MUST return `"Ruimtelijke ordening"` and `"Milieubeheer"`
  respectively

#### Scenario: Unknown code is invalid
- **GIVEN** the shipped taakveld list
- **WHEN** `Iv3TaakveldList::isValidCode('99.9')` is called
- **THEN** it MUST return `false`

### Requirement: Case type IV3 taakveld classification
Case types MUST support an optional IV3 taakveld code so cases created under
that case type can be classified for IV3 reporting purposes.

#### Scenario: Case type without a taakveld remains valid
- **GIVEN** a `caseType` object with no `iv3Taakveld` value set
- **WHEN** the case type is saved
- **THEN** the save MUST succeed (the field is optional, not required for
  publish)

### Requirement: Per-case cost recording
Cases MUST support recording zero or more dated cost entries, each typed as
either leges income or handling cost, for later IV3 aggregation.

#### Scenario: A case can accumulate multiple cost entries
- **GIVEN** a case with an empty `kosten` array
- **WHEN** two entries are appended — `{bedrag: 150.00, type: "leges_income", datum: "2026-04-10"}`
  and `{bedrag: 40.50, type: "handling_cost", datum: "2026-04-12"}`
- **THEN** the case's decoded `kosten` array MUST contain both entries
  unmodified

### Requirement: Quarterly IV3 aggregation
The system MUST aggregate, per taakveld and per quarter, the number of
distinct cases with cost activity, the total recorded (handling) costs, the
total leges income, and the average cost per case; cases whose case type
carries no taakveld MUST be excluded from taakveld buckets and reported
separately as uncategorized.

#### Scenario: A single case with one taakveld aggregates correctly
- **GIVEN** a `caseType` with `iv3Taakveld: "8.1"` and one `case` of that type
  with `kosten: [{bedrag: 100, type: "leges_income", datum: "2026-04-05"},
  {bedrag: 60, type: "handling_cost", datum: "2026-04-06"}]`
- **WHEN** `Iv3ReportService::generateQuarterlyReport(2026, 2)` is called
- **THEN** `perTaakveld["8.1"].caseCount` MUST be `1`
- **AND** `perTaakveld["8.1"].totalCosts` MUST be `60`
- **AND** `perTaakveld["8.1"].totalLegesIncome` MUST be `100`
- **AND** `perTaakveld["8.1"].avgCostPerCase` MUST be `60`

#### Scenario: Multiple cases across multiple taakvelden are kept separate
- **GIVEN** case A (taakveld `8.1`) with a `handling_cost` entry of `50` dated
  in Q2 2026, and case B (taakveld `7.4`) with a `handling_cost` entry of
  `200` dated in Q2 2026
- **WHEN** `Iv3ReportService::generateQuarterlyReport(2026, 2)` is called
- **THEN** `perTaakveld["8.1"].totalCosts` MUST be `50` and
  `perTaakveld["7.4"].totalCosts` MUST be `200`
- **AND** neither bucket's `caseCount` MUST include the other taakveld's case

#### Scenario: Quarter boundaries are exact
- **GIVEN** a case with a `handling_cost` entry dated `2026-06-30` (last day
  of Q2) and another entry dated `2026-07-01` (first day of Q3), both on
  taakveld `1.1`
- **WHEN** `Iv3ReportService::generateQuarterlyReport(2026, 2)` and
  `generateQuarterlyReport(2026, 3)` are both called
- **THEN** the Q2 report MUST include only the `2026-06-30` entry's amount
- **AND** the Q3 report MUST include only the `2026-07-01` entry's amount

#### Scenario: Cases without a taakveld are excluded and reported as uncategorized
- **GIVEN** a case whose case type has no `iv3Taakveld` set, with a
  `handling_cost` entry of `75` dated in the requested quarter
- **WHEN** `Iv3ReportService::generateQuarterlyReport()` is called for that
  quarter
- **THEN** the case's cost MUST NOT appear in any `perTaakveld` bucket
- **AND** `uncategorized.totalCosts` MUST be `75` and `uncategorized.caseCount`
  MUST be `1`

#### Scenario: A quarter with no qualifying cost activity returns an empty report
- **GIVEN** no case has any `kosten` entry dated within the requested quarter
- **WHEN** `Iv3ReportService::generateQuarterlyReport()` is called for that
  quarter
- **THEN** `perTaakveld` MUST be an empty array/object
- **AND** `uncategorized` MUST be `null`

#### Scenario: A case with no cost activity this quarter does not inflate the case count
- **GIVEN** a case classified under taakveld `3.1` with a cost entry dated in
  Q1 2026 and no entries dated in Q2 2026
- **WHEN** `Iv3ReportService::generateQuarterlyReport(2026, 2)` is called
- **THEN** taakveld `3.1` MUST NOT appear in the Q2 `perTaakveld` result (or
  MUST appear with `caseCount: 0` only if no other case qualifies it — the
  case contributes nothing to Q2)

### Requirement: CSV export
The quarterly report MUST be downloadable as CSV with one row per taakveld
(plus an uncategorized row when present), matching the JSON aggregation
exactly.

#### Scenario: CSV contains a header and one row per taakveld
- **GIVEN** a quarterly report with two taakveld buckets and no uncategorized
  bucket
- **WHEN** `Iv3ReportService::asCsv($report)` is called
- **THEN** the first line MUST be the column header
  `taakveld,label,caseCount,totalCosts,totalLegesIncome,avgCostPerCase`
- **AND** there MUST be exactly two further data lines, one per taakveld

#### Scenario: CSV includes an uncategorized row when present
- **GIVEN** a quarterly report whose `uncategorized` bucket is non-null
- **WHEN** `Iv3ReportService::asCsv($report)` is called
- **THEN** the CSV MUST contain one additional row with an empty `taakveld`
  column and label `Uncategorized`

### Requirement: Report endpoint authorization
The IV3 report figures endpoint MUST be restricted to controllers/beheerders/
admins; the taakveld reference list endpoint MUST be available to any
authenticated user.

#### Scenario: A user outside the allowed groups is denied the report
- **GIVEN** an authenticated user who is not an NC admin and not in
  `controllers`/`beheerders`
- **WHEN** they call `GET /api/reports/iv3?year=2026&quarter=2`
- **THEN** the response MUST be `403 Forbidden` and MUST NOT include report
  data

#### Scenario: A controller-group user receives the report
- **GIVEN** an authenticated user in the `controllers` group
- **WHEN** they call `GET /api/reports/iv3?year=2026&quarter=2`
- **THEN** the response MUST be `200 OK` with the JSON report body

#### Scenario: An unauthenticated request is rejected
- **GIVEN** no active user session
- **WHEN** `GET /api/reports/iv3?year=2026&quarter=2` is called
- **THEN** the response MUST be `401 Unauthorized`

#### Scenario: CSV format returns a file download
- **GIVEN** an authenticated user in the `admin` group (fallback path)
- **WHEN** they call `GET /api/reports/iv3?year=2026&quarter=2&format=csv`
- **THEN** the response MUST be a `text/csv` download, not a JSON body

#### Scenario: The taakveld list is readable by any authenticated user
- **GIVEN** an authenticated user in no special group
- **WHEN** they call `GET /api/reports/iv3/taakvelden`
- **THEN** the response MUST be `200 OK` with the taakveld list
