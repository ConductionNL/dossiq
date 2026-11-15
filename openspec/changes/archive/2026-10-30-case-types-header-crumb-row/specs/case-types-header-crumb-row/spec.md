# case-types-header-crumb-row Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [case-types-header-crumb-row](../../)

## Purpose

The Case types list header is the board's: a title, then the breadcrumb row with the count.

## ADDED Requirements

### Requirement: The case types list keeps its count in the breadcrumb row

The simple profile's `CaseTypes` page SHALL NOT set `countSubtitle`, and its `headerMeta` SHALL carry `{total}`.

#### Scenario: Case types header

- **GIVEN** the Case types list in the simple profile
- **THEN** "Instellingen / Zaaktypen · 54 zaaktypen" stands straight under the title, as on board DqZaaktypen
