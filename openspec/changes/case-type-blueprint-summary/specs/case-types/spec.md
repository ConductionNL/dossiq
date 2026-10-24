# case-types Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [case-type-blueprint-summary](../../)

## Purpose

What a case type offers reads at a glance.

## ADDED Requirements

### Requirement: The blueprint reads as a summary card

The case type blueprint SHALL render as a titled card that states the number of statuses with how many are final, the
number of results, and the number of attributes with how many are inherited and from which parent, and SHALL keep every
row reachable in the same card.

#### Scenario: DqZaaktypeStatussen

- **GIVEN** a case type with 6 statuses of which 2 final, 3 results and 14 attributes of which 6 inherited
- **THEN** the card reads "6, waarvan 2 eindstatussen", "3" and "14 velden, 6 geërfd van Omgevingsvergunning"
