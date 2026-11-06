# index-list-labels Specification

## Purpose
What dossiq does for index list labels. The requirements were first written by the change `r21-list-search-labels` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-r21-list-search-labels/`) and cover: List search fields and columns read as the boards.

## Requirements

### Requirement: List search fields and columns read as the boards

In the simple profile the task list SHALL show the search placeholder "Zoek op taak of zaaknummer" and head its due
column "Deadline", and the case type list SHALL show the search placeholder "Zoek een zaaktype".

#### Scenario: Task list in Dutch

- **GIVEN** the task list in the simple profile, in Dutch
- **THEN** the search field reads "Zoek op taak of zaaknummer" and the due column "Deadline", as on board DqTaken

#### Scenario: Case type list in Dutch

- **GIVEN** the case type list in the simple profile, in Dutch
- **THEN** the search field reads "Zoek een zaaktype", as on board DqZaaktypen
