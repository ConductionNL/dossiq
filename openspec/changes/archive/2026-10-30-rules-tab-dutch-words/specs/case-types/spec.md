# case-types Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [rules-tab-dutch-words](../../)

## Purpose

The Rules tab reads in Dutch.

## ADDED Requirements

### Requirement: Every Rules tab row reads in Dutch

Every title and line the Rules tab shows for the case schema's rules SHALL have a Dutch translation.

#### Scenario: DqZaaktypeRegels

- **WHEN** an administrator opens the Rules tab in Dutch
- **THEN** a row reads "Dagen te laat" with "Berekend uit de zaak, bij elk opslaan." under it, not "Days overdue"
