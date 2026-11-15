# case-types Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [case-type-statuses-colour-words](../../)

## Purpose

The Statuses table says a status's colour in words.

## ADDED Requirements

### Requirement: The Statuses table writes the list colour as a word

The Statuses table on a case type SHALL show each status's list colour as its translated palette name in plain text.

#### Scenario: DqZaaktypeStatussen

- **WHEN** an administrator opens the Statuses tab
- **THEN** the colour column reads Blauw, Oranje, Paars, Groenblauw, Groen, Rood as plain words
