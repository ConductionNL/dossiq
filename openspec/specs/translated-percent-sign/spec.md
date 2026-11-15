# translated-percent-sign Specification

## Purpose
What dossiq does for translated percent sign. The requirements were first written by the change `translated-percent-sign` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-translated-percent-sign/`) and cover: A text translated without parameters survives formatting.

## Requirements

### Requirement: A text translated without parameters survives formatting

Every text that lib/ translates without parameters, and its Dutch translation, SHALL format with vsprintf() and no
arguments: a literal percent sign is written `%%`.

#### Scenario: DqDoorlooptijd Monthly SLA trend

- **GIVEN** a Dutch reader on Doorlooptijd
- **WHEN** the Monthly SLA trend card asks `/api/reports/processing-time?view=trend`
- **THEN** the report answers with the trend instead of "Report generation failed"

#### Scenario: Doorlooptijd CSV

- **WHEN** the reader downloads the processing-time CSV
- **THEN** the last header reads "Binnen de norm (%)"
