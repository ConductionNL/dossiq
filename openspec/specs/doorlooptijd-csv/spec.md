# doorlooptijd-csv Specification

## Purpose
What dossiq does for doorlooptijd csv. The requirements were first written by the change `doorlooptijd-csv` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-doorlooptijd-csv/`) and cover: The processing-time figures download as CSV.

## Requirements

### Requirement: The processing-time figures download as CSV

`GET /api/doorlooptijd/metrics` with `format=csv` SHALL answer a CSV download with, per case type and month of the
requested period and for the requested case type only when one is given, the number of closed cases, their rounded
average processing days and the rounded share closed within their deadline (empty when none had a deadline). It SHALL
refuse a reader outside the reporting audience and an unknown `format` before reading anything. A cell that starts with
`=`, `+`, `-`, `@`, a tab or a carriage return SHALL be prefixed with `'`.

#### Scenario: DqDoorlooptijd Downloaden

- **GIVEN** the Doorlooptijd page with the period "Quarter" and the case type Woo-verzoek chosen
- **WHEN** the reader activates "Downloaden"
- **THEN** `doorlooptijd.csv` holds one row per month of the last three months in which a Woo-verzoek closed
