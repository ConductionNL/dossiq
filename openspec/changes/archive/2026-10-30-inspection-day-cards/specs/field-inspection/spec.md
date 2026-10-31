## ADDED Requirements

### Requirement: The inspector's day names each inspection by its place

The day list SHALL draw each of today's inspections as a card titled by its location, else by its case's title, with its time, its checklist and its state.

#### Scenario: Pieter's day
@e2e exclude config and data pinned by fieldInspectionBoard.spec.js and ZuiddrechtFieldInspectionDayTest

- **WHEN** Pieter opens Inspectie in het veld
- **THEN** the first card SHALL read "Molenweg 4" with "Klaar"
