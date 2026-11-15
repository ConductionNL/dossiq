# workplace Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [search-placeholder-default](../../)

## Purpose

Dossiq names the words of the top bar's search field.

## ADDED Requirements

### Requirement: Dossiq words the search field

Dossiq SHALL declare the app config key `search_placeholder` with the default "Search cases, documents or contacts",
translated in its catalogue, and SHALL leave every other app config key as it was.

#### Scenario: DqKop

- **WHEN** a Dutch user opens a dossiq page on an instance with thematiq's search field words
- **THEN** the search field reads "Zoek zaken, documenten of contacten"
