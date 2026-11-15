---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# termijn-reporting Specification

## Purpose
Produces termijn and dwangsom reporting for management and audit, including a quarterly KPI report per zaaktype and an annual dwangsom audit report for the jaarrekening, both exportable as CSV/JSON. It also exposes an aggregated dashboard KPI endpoint returning within-termijn percentage, average duration, overrun count, and dwangsom totals, cached and refreshed at least hourly.

## Requirements

### Requirement: Reporting voor management en accountant (REQ-TERM-009)

The system SHALL produce a quarterly termijn-KPI report per zaaktype and an annual dwangsom audit report for the jaarrekening, and SHALL expose an aggregated dashboard KPI endpoint.

#### Scenario: Quarterly KPI report per zaaktype

- **GIVEN** an afdelingshoofd requests the kwartaalrapport for a period
- **WHEN** the report is generated
- **THEN** the response SHALL include, per zaaktype, totaal-zaken, percentage-binnen-termijn, gemiddelde-doorlooptijd, aantal-verlengingen, aantal-overschrijdingen, aantal-ingebrekestellingen, and totaal-dwangsom-uitgekeerd
- **AND** the report SHALL be exportable as CSV/JSON with report metadata

#### Scenario: Annual dwangsom audit report

- **GIVEN** an accountant requests the jaaroverzicht for a year
- **WHEN** the report is generated
- **THEN** a CSV/JSON SHALL list all dwangsommen with zaak-referentie, zaaktype, ingebrekestelling-datum, beschikking-datum, dwangsom-bedrag, betaal-datum, betalings-referentie, and status
- **AND** the report SHALL include summary statistics (total records, total amount, count by status)

#### Scenario: Dashboard KPI endpoint returns aggregates

- **GIVEN** the dashboard requests the termijn KPI widget
- **WHEN** `GET /api/dossiq/dashboard/termijn-kpi` is called
- **THEN** the response SHALL return totalZaken, withinTermijnPercent, avgDurationDays, overrunCount, dwangsomTotal, and lastUpdated
- **AND** the aggregate SHALL be cached and refreshed at least hourly

### Requirement: Progress and days left are computed at read time (REQ-TERM-068)

A case SHALL carry a progress figure derived from the phases completed and
the term consumed, and a days-left count, both computed at read time from
the bound terms. Neither SHALL be stored. Both SHALL be on the case page,
and the same computation SHALL feed the list column when the library
offers one.

#### Scenario: a handler triages by progress
@e2e tests/e2e/phase-terms-and-the-internal-target.spec.ts

- **GIVEN** a case two phases into four with half its term consumed
- **WHEN** a handler opens it
- **THEN** it SHALL show a progress figure and a days-left count

#### Scenario: an extension moves the figure without a write
@e2e tests/e2e/phase-terms-and-the-internal-target.spec.ts

- **GIVEN** a case showing 4 days left
- **WHEN** its term is extended by 14 days
- **THEN** the next read SHALL show 18 days left
- **AND** no progress value SHALL have been stored

#### Scenario: the list and the case page agree

- **GIVEN** a case rendered in a list and on its page
- **WHEN** both are read
- **THEN** the progress figure SHALL be the same in both

### Requirement: The age of the open workload is answerable per status (REQ-TERM-069)

dossiq SHALL report how old the open workload is now, grouped by status,
over cases that are still open. It SHALL be one aggregation over the
object store and SHALL NOT be a stored report or a nightly job. It SHALL
NOT be computed from closed cases.

#### Scenario: a teamleider sees the age of what is still standing
@e2e tests/e2e/phase-terms-and-the-internal-target.spec.ts

- **GIVEN** open cases sitting in three statuses
- **WHEN** the workload age report is read
- **THEN** it SHALL give an age per status over the open cases

#### Scenario: closed cases do not enter the number

- **GIVEN** a status holding both open and closed cases
- **WHEN** the workload age is read
- **THEN** only the open cases SHALL be counted

#### Scenario: the report is read live

- **GIVEN** a case that has just changed status
- **WHEN** the workload age is read again
- **THEN** the case SHALL be counted under its new status

### Requirement: The annual audit lists the forfeited payments by case

`GET /api/deadlines/reports/penalty-payments?year=YYYY` SHALL answer, for a caller in the reporting audience, every
penalty payment forfeited in that year, newest forfeit first, each with the case id, number and title, the case type's
title, the requester, the forfeit day (the day the calculation stopped, else its start), the amount in euros and the
payment status, plus the count, the total and the number disputed. A year outside 2020..2100 SHALL be refused with 400,
a caller outside the audience with 403. A payment whose case cannot be read SHALL still be listed with what is known.

#### Scenario: Audit 2026

- **GIVEN** three payments forfeited in 2026 (one disputed) and one in 2025
- **WHEN** the audit for 2026 is read
- **THEN** it lists the three, newest first, each named by its case, totalling the three amounts, one disputed

### Requirement: The report cards carry their own form

The deadline dashboard SHALL draw the quarterly report with a quarter field and "Rapport laden", and the annual audit
with a year field and "Audit laden", inside their cards; it SHALL NOT offer the quarter and year as page filters.

#### Scenario: Load another quarter

- **WHEN** the reader enters 2025-Q4 and presses "Rapport laden"
- **THEN** the quarterly table reads 2025-Q4

### Requirement: the deadline dashboard lists each notice of default by its case, with its penalty

The deadline dashboard MUST list every notice of default, newest receipt first, with its case (opening the case), the day it came in, its assessment, the penalty it started and where that money stands, read from `GET /api/deadlines/notices` behind the reporting audience gate.

#### Scenario: a fixed penalty
- GIVEN notice 2026-4863 Kapvergunning Parkstraat, received 7 August, valid, its penalty fixed at € 1.442
- THEN its row reads "Ontvangen 7 aug", "Geldig", "€ 1.442, vastgesteld" and "Betaalsignaal verstuurd"

#### Scenario: a running penalty
- GIVEN a valid notice whose penalty has run since 16 October and stands at € 207
- THEN its row reads "€ 207, loopt sinds 16 okt" and "Bedrag nog niet vast"

#### Scenario: no penalty
- GIVEN a notice received while the term still ran
- THEN its row reads "Te vroeg: termijn loopt nog", "Geen" and "Niet van toepassing"

#### Scenario: outside the audience
- GIVEN a caller outside the reporting audience
- THEN the endpoint answers 403 and reads nothing
