# termijn-reporting Delta

## ADDED Requirements

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
