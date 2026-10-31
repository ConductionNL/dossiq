# termijnen-week-and-footnote Delta

**Status**: proposed
**Scope**: dossiq

## ADDED Requirements

### Requirement: The quarterly report summarises the decided terms

The quarterly report SHALL carry `summaryRows`: one row with `period`, `quarter`, `year`, `decided` (terms met or
missed), `withinTerm` (terms met) and `share` (the whole percentage met), and no row when nothing was decided.
DqTermijnen SHALL draw it under the quarterly table as "Kwartaal {quarter} van {year}: {decided} zaken beslist,
{withinTerm} binnen de wettelijke of verlengde termijn ({share} %)."

#### Scenario: three of four decided terms met

- **GIVEN** four terms in 2026-Q2 that were decided, three of them in time
- **THEN** `summaryRows` is `[{ period: "2026-Q2", quarter: 2, year: 2026, decided: 4, withinTerm: 3, share: 75 }]`

### Requirement: The deadline page names the week

The TermijnDashboard description SHALL read "{today|week} · all teams" ("Week 41 · alle teams").

#### Scenario: the deadline page in week 41

- **GIVEN** today falls in ISO week 41
- **WHEN** the deadline page opens
- **THEN** its description reads "Week 41 · alle teams"
