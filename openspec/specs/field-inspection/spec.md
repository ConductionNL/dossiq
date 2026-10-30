# field-inspection Specification

## Purpose
What dossiq does for field inspection. The requirements were first written by the change `screens-round12-field-inspection` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-screens-round12-field-inspection/`) and cover: An inspector's day, checklist and outbox are on one page (REQ-FI-1); The inspector's day names each inspection by its place.

## Requirements

### Requirement: An inspector's day, checklist and outbox are on one page (REQ-FI-1)

The manifest page `FieldInspection` (route `/field-inspection`, type
`dashboard`) SHALL show three device frames: today's `fieldInspection` records
for the reader, the reader's `inspectionChecklistRun` in execution, and the
`syncQueue` items with status pending, conflict or failed.

#### Scenario: A failed sync shows in the Synchronise frame
@e2e exclude measured against board DqInspectie by the round 12 pixel check, not an e2e flow

- **GIVEN** a `syncQueue` item with status failed
- **WHEN** you open the field inspection view
- **THEN** the Synchronise frame SHALL list it with its status

#### Scenario: Today's inspections read as time, checklist and state
@e2e exclude config pinned by tests/vitest/fieldInspectionBoard.spec.js and measured against board DqInspectie by COMPDIFF

- **GIVEN** an inspection planned today at 09:30 on the "Bouwtoezicht fase 3 - Oplevering" checklist, finished and synchronised
- **WHEN** the inspector opens the page
- **THEN** its row SHALL read "09:30 · Bouwtoezicht fase 3 - Oplevering" with the state "Klaar" in green

#### Scenario: A started checklist opens where the inspector stopped
@e2e exclude config pinned by tests/vitest/fieldInspectionBoard.spec.js and measured against board DqInspectie by COMPDIFF

- **GIVEN** the inspector's run in execution with its first six questions answered
- **WHEN** the page opens
- **THEN** the Checklist frame SHALL show question 7

### Requirement: The inspector's day names each inspection by its place

The day list SHALL draw each of today's inspections as a card titled by its location, else by its case's title, with its time, its checklist and its state.

#### Scenario: Pieter's day
@e2e exclude config and data pinned by fieldInspectionBoard.spec.js and ZuiddrechtFieldInspectionDayTest

- **WHEN** Pieter opens Inspectie in het veld
- **THEN** the first card SHALL read "Molenweg 4" with "Klaar"
