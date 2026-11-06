# index-list-labels Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [r21-list-search-labels](../../)

## ADDED Requirements

### Requirement: List search fields and columns read as the boards

In the simple profile the task list SHALL show the search placeholder "Zoek op taak of zaaknummer" and head its due
column "Deadline", and the case type list SHALL show the search placeholder "Zoek een zaaktype".

#### Scenario: Task list in Dutch

- **GIVEN** the task list in the simple profile, in Dutch
- **THEN** the search field reads "Zoek op taak of zaaknummer" and the due column "Deadline", as on board DqTaken

#### Scenario: Case type list in Dutch

- **GIVEN** the case type list in the simple profile, in Dutch
- **THEN** the search field reads "Zoek een zaaktype", as on board DqZaaktypen
