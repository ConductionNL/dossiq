# case-types Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [case-type-lists-strong-names](../../)

## Purpose

The case type's lists name their rows as the board draws them.

## ADDED Requirements

### Requirement: Status and result names are strong text, a status with its colour dot

The Statussen and Resultaten tables SHALL draw their names as strong text without an underline, the Statussen names
with a dot in the status colour, and the archival action in sentence case.

#### Scenario: DqZaaktypeStatussen

- **WHEN** an administrator opens the Statussen tab
- **THEN** "Ontvangen" has a blue dot and no underline, and a result's archival action reads "Vernietigen"
