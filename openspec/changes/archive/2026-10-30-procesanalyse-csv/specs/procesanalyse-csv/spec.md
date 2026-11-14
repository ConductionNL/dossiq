# procesanalyse-csv Delta

**Status**: proposed
**Scope**: dossiq

## ADDED Requirements

### Requirement: The days per phase download as CSV

`GET /api/reports/process-mining` with `format=csv` SHALL answer a CSV download with a column per phase and a row per
case type, each cell the rounded days spent in that phase as the mean, or the median when `statistic=median`, for the
requested case type only when one is given. A phase the case type never passes SHALL read "none" in the reader's
language. It SHALL refuse a reader outside the controller, beheerder or admin groups, an unknown `format` and an
unknown `statistic` before reading anything.

#### Scenario: DqProcesanalyse Downloaden

- **GIVEN** the Procesanalyse page with "Mediaan" chosen
- **WHEN** the reader activates "Downloaden"
- **THEN** `procesanalyse.csv` holds the median days per phase per case type the matrix shows
