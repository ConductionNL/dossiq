# case-types Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [case-type-blueprint-summary-look](../../)

## Purpose

The blueprint card's facts read as the board draws them.

## ADDED Requirements

### Requirement: The blueprint facts are a two-column list on the card's inset

The blueprint summary card SHALL draw its facts as a label column as wide as the longest label, left aligned, beside the
values, 8px 16px apart, without an inset of its own inside the card.

#### Scenario: DqZaaktypeStatussen

- **WHEN** an administrator opens the Statussen tab
- **THEN** "Statussen", "Resultaten" and "Kenmerken" start at the card's inset with their values beside them
